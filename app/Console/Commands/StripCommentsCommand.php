<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class StripCommentsCommand extends Command
{
    protected $signature = 'code:strip-comments
                            {path?   : Path relatif dari base_path (default: seluruh project)}
                            {--dry   : Dry-run, tampilkan file yang akan diubah tanpa menulis}
                            {--ext=  : Ekstensi file, default php}';

    protected $description = 'Hapus semua komentar PHP (// dan /* */) kecuali blok yang mengandung anotasi OpenAPI (@OA\\)';

    public function handle(): int
    {
        $basePath = $this->argument('path')
            ? base_path($this->argument('path'))
            : base_path();

        $ext = $this->option('ext') ?: 'php';
        $dry = (bool) $this->option('dry');

        if (! is_dir($basePath) && ! is_file($basePath)) {
            $this->error("Path tidak ditemukan: {$basePath}");
            return self::FAILURE;
        }

        $files = [];
        if (is_file($basePath)) {
            $files = [new \SplFileInfo($basePath)];
        } else {
            $finder = Finder::create()
                ->files()
                ->name("*.{$ext}")
                ->in($basePath)
                ->exclude(['vendor', 'node_modules', 'storage', '.git']);
            $files = iterator_to_array($finder);
        }

        $totalFiles = 0;
        $totalRemoved = 0;

        foreach ($files as $file) {
            $path = $file->getRealPath();
            $original = file_get_contents($path);
            $result = $this->stripComments($original);

            if ($result === $original) {
                continue;
            }

            $removed = substr_count($original, "\n") - substr_count($result, "\n");
            $totalFiles++;
            $totalRemoved += max(0, $removed);

            $relative = str_replace(base_path() . '/', '', $path);

            if ($dry) {
                $this->line("  <comment>[DRY]</comment> {$relative} <fg=yellow>(-{$removed} lines)</>");
            } else {
                file_put_contents($path, $result);
                $this->line("  <info>✓</info> {$relative} <fg=yellow>(-{$removed} lines)</>");
            }
        }

        $this->newLine();
        if ($dry) {
            $this->info("[DRY-RUN] {$totalFiles} file akan diubah, ~{$totalRemoved} baris komentar dihapus.");
        } else {
            $this->info("{$totalFiles} file diubah, ~{$totalRemoved} baris komentar dihapus.");
        }

        return self::SUCCESS;
    }

    private function stripComments(string $source): string
    {
        $tokens = token_get_all($source);
        $output = '';

        foreach ($tokens as $token) {
            if (is_string($token)) {
                $output .= $token;
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_COMMENT) {
                if ($this->isOpenApiComment($text)) {
                    $output .= $text;
                } else {
                    $output .= $this->blankOutComment($text);
                }
                continue;
            }

            if ($id === T_DOC_COMMENT) {
                if ($this->isOpenApiComment($text)) {
                    $output .= $text;
                } else {
                    $output .= $this->blankOutComment($text);
                }
                continue;
            }

            $output .= $text;
        }

        $output = $this->collapseBlankLines($output);

        return $output;
    }

    private function isOpenApiComment(string $text): bool
    {
        return (bool) preg_match('/@OA\\\\/', $text);
    }

    private function blankOutComment(string $text): string
    {
        if (str_starts_with($text, '//')) {
            return '';
        }

        $lineCount = substr_count($text, "\n");
        if ($lineCount === 0) {
            return '';
        }

        return '';
    }

    private function collapseBlankLines(string $text): string
    {
        $text = preg_replace('/^[ \t]+$/m', '', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return $text;
    }
}
