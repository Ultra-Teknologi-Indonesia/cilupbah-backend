<?php

namespace Tests\Unit;

use App\Support\FriendlyError;
use PHPUnit\Framework\TestCase;

class FriendlyErrorTest extends TestCase
{
    public function test_import_keeps_friendly_validation_text(): void
    {
        $this->assertSame(
            'Baris 5: kolom SKU wajib diisi',
            FriendlyError::import('Baris 5: kolom SKU wajib diisi'),
        );
    }

    public function test_import_replaces_technical_text(): void
    {
        $msg = FriendlyError::import('SQLSTATE[23000]: Integrity constraint violation');
        $this->assertStringContainsString('Gagal memproses file', $msg);
        $this->assertStringNotContainsString('SQLSTATE', $msg);
    }

    public function test_import_null_stays_null(): void
    {
        $this->assertNull(FriendlyError::import(null));
        $this->assertNull(FriendlyError::import('   '));
    }

    public function test_generic_keeps_friendly_message(): void
    {
        $this->assertSame(
            'PO tidak dapat dihapus karena sudah diterima.',
            FriendlyError::generic('PO tidak dapat dihapus karena sudah diterima.', 'Gagal menghapus PO.'),
        );
    }

    public function test_generic_falls_back_on_technical(): void
    {
        $this->assertSame(
            'Gagal menghapus PO.',
            FriendlyError::generic('Call to a member function id() on null', 'Gagal menghapus PO.'),
        );
        $this->assertSame(
            'Gagal.',
            FriendlyError::generic('RuntimeException in /var/www/app/Foo.php on line 42', 'Gagal.'),
        );
    }

    public function test_generic_empty_uses_fallback(): void
    {
        $this->assertSame('Gagal.', FriendlyError::generic('', 'Gagal.'));
        $this->assertSame('Gagal.', FriendlyError::generic(null, 'Gagal.'));
    }
}
