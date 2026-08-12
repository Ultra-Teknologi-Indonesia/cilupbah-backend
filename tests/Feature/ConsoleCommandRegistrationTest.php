<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class ConsoleCommandRegistrationTest extends TestCase
{

    private const SENGAJA_TIDAK_DIDAFTARKAN = [];

    private function commandClassesInModules(): array
    {
        $root = base_path('Modules');

        if (! is_dir($root)) {
            return [];
        }

        $files = Finder::create()->files()->in($root)->path('Console/Commands')->name('*.php');

        $classes = [];

        foreach ($files as $file) {
            $class = $this->classNameFor($file);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Command::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    private function classNameFor(SplFileInfo $file): ?string
    {
        $contents = file_get_contents($file->getRealPath());

        if (! preg_match('/^namespace\s+([^;]+);/m', $contents, $matches)) {
            return null;
        }

        return trim($matches[1]) . '\\' . $file->getBasename('.php');
    }

    public function test_every_module_command_is_registered(): void
    {
        $registered = array_keys(Artisan::all());
        $missing = [];

        foreach ($this->commandClassesInModules() as $class) {
            if (in_array($class, self::SENGAJA_TIDAK_DIDAFTARKAN, true)) {
                continue;
            }

            $name = app($class)->getName();

            if (! in_array($name, $registered, true)) {
                $missing[] = "{$name} ({$class})";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Command berikut punya file tapi tidak terdaftar di ServiceProvider modulnya, "
            . "jadi tidak bisa dijalankan sama sekali:\n- " . implode("\n- ", $missing),
        );
    }

    public function test_every_scheduled_command_exists(): void
    {
        $registered = array_keys(Artisan::all());
        $unknown = [];

        foreach (app(Schedule::class)->events() as $event) {
            if (! preg_match('/artisan[\'"]?\s+([\w:-]+)/', $event->command ?? '', $matches)) {
                continue;
            }

            $name = $matches[1];

            if (! in_array($name, $registered, true)) {
                $unknown[] = $name;
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($unknown)),
            "Jadwal memanggil command yang tidak ada. Scheduler akan gagal diam-diam tiap kali jadwalnya jatuh tempo:\n- "
            . implode("\n- ", array_unique($unknown)),
        );
    }
}
