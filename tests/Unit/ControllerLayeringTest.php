<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ControllerLayeringTest extends TestCase
{
    public function test_controllers_do_not_bypass_application_services(): void
    {
        $roots = [
            dirname(__DIR__, 2).'/app/Http/Controllers',
            dirname(__DIR__, 2).'/Modules',
        ];
        $violations = [];

        foreach ($this->controllerFiles($roots) as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $lineNumber => $line) {
                if (preg_match(
                    '/use\s+[^;]*Repositories\\\\|DB::|(?:^|[^\\w])app\s*\(|::dispatch\s*|::(?:query|where(?:[A-Z][A-Za-z]+)?|with|find(?:OrFail)?|first(?:OrFail)?|create|insert|upsert|destroy)\s*\\(/',
                    $line,
                )) {
                    $violations[] = sprintf('%s:%d:%s', $file, $lineNumber + 1, trim($line));
                }

            }
        }

        self::assertSame([], $violations, "Controller layering violations:\n".implode("\n", $violations));
    }

    private function controllerFiles(array $roots): \Generator
    {
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && str_ends_with($file->getFilename(), 'Controller.php')) {
                    yield $file->getPathname();
                }
            }
        }
    }
}
