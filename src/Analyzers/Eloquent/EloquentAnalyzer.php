<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Analyzers\Eloquent;

use SdPayHub\Wraith\Analyzers\AbstractAnalyzer;
use SdPayHub\Wraith\Contracts\Analyzer;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;

/**
 * Static Eloquent model inspection via file parsing / reflection.
 */
final class EloquentAnalyzer extends AbstractAnalyzer implements Analyzer
{
    public function category(): string
    {
        return Category::ELOQUENT;
    }

    public function name(): string
    {
        return 'Eloquent';
    }

    public function supports(): bool
    {
        return is_dir(app_path('Models')) || is_dir(app_path());
    }

    public function analyze(): AnalysisResult
    {
        $findings = [];
        $files = $this->modelFiles();

        foreach ($files as $file) {
            $findings = array_merge($findings, $this->inspectFile($file));
        }

        return $this->result($this->name(), $this->category(), $findings);
    }

    /**
     * @return array<int, string>
     */
    private function modelFiles(): array
    {
        $paths = [];

        if (is_dir(app_path('Models'))) {
            $paths = array_merge($paths, glob(app_path('Models').'/*.php') ?: []);
        }

        // Laravel 8 often placed models in app/
        foreach (glob(app_path().'/*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            if (strpos($contents, 'extends Model') !== false || strpos($contents, 'extends \\Illuminate\\Database\\Eloquent\\Model') !== false) {
                $paths[] = $file;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function inspectFile(string $file): array
    {
        $contents = (string) file_get_contents($file);
        $class = basename($file, '.php');
        $findings = [];

        $hasFillable = preg_match('/\$fillable\s*=/', $contents) === 1;
        $hasGuarded = preg_match('/\$guarded\s*=/', $contents) === 1;

        if (! $hasFillable && ! $hasGuarded) {
            $findings[] = $this->finding(
                Severity::HIGH,
                $this->category(),
                'eloquent.mass_assignment_unprotected',
                sprintf('Model %s defines neither $fillable nor $guarded.', $class),
                'Unprotected mass assignment allows attackers to set unexpected attributes.',
                'Add a $fillable or $guarded property to '.$class.'.',
                'https://laravel.com/docs/eloquent#mass-assignment',
                false,
                ['file' => $file, 'class' => $class]
            );
        } elseif ($hasGuarded && preg_match('/\$guarded\s*=\s*\[\s*\]/', $contents) === 1 && ! $hasFillable) {
            $findings[] = $this->finding(
                Severity::HIGH,
                $this->category(),
                'eloquent.unguarded_model',
                sprintf('Model %s has $guarded = [] (fully unguarded).', $class),
                'Fully unguarded models accept any attribute via mass assignment.',
                'Prefer an explicit $fillable list.',
                'https://laravel.com/docs/eloquent#mass-assignment',
                false,
                ['file' => $file, 'class' => $class]
            );
        }

        $usesSoftDeletes = strpos($contents, 'SoftDeletes') !== false;
        $mentionsDeletedAt = preg_match('/deleted_at/', $contents) === 1
            || preg_match("/'deleted_at'|\"deleted_at\"/", $contents) === 1;

        // Casts check for dates/json — Version-sensitive: $casts array vs casts() method (L11+).
        $hasCastsProperty = preg_match('/\$casts\s*=/', $contents) === 1;
        $hasCastsMethod = preg_match('/function\s+casts\s*\(/', $contents) === 1;

        if (preg_match('/\b(created_at|updated_at|email_verified_at|published_at)\b/', $contents)
            && ! $hasCastsProperty
            && ! $hasCastsMethod
            && strpos($contents, 'datetime') === false) {
            // Weak signal — only flag if timestamps appear in fillable without casts.
            if (preg_match('/\$fillable\s*=\s*\[[^\]]*(verified_at|published_at|expires_at)[^\]]*\]/s', $contents)) {
                $findings[] = $this->finding(
                    Severity::LOW,
                    $this->category(),
                    'eloquent.missing_casts',
                    sprintf('Model %s may be missing casts for date/JSON attributes.', $class),
                    'Uncast date/JSON attributes are returned as strings and are easy to misuse.',
                    'Add $casts (or a casts() method on Laravel 11+) for date and JSON columns.',
                    'https://laravel.com/docs/eloquent-mutators#attribute-casting',
                    false,
                    ['file' => $file, 'class' => $class]
                );
            }
        }

        if ($usesSoftDeletes && ! $mentionsDeletedAt && ! preg_match('/SoftDeletes/', $contents)) {
            // SoftDeletes trait implies deleted_at — no finding.
        }

        if (! $usesSoftDeletes && preg_match('/[\'"]deleted_at[\'"]/', $contents)) {
            $findings[] = $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'eloquent.deleted_at_without_soft_deletes',
                sprintf('Model %s references deleted_at but does not use SoftDeletes.', $class),
                'A deleted_at column without SoftDeletes will not filter soft-deleted rows automatically.',
                'Add the SoftDeletes trait or remove the deleted_at column.',
                'https://laravel.com/docs/eloquent#soft-deleting',
                false,
                ['file' => $file, 'class' => $class]
            );
        }

        return $findings;
    }
}
