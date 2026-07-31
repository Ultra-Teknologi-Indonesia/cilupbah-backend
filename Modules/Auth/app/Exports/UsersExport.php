<?php

namespace Modules\Auth\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Database\Eloquent\Builder;

class UsersExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(
        protected Builder $query
    ) {}

    public function query()
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nama Lengkap',
            'Email',
            'NIK',
            'Peran (Role)',
            'Gudang Default (Location ID)',
            'Gudang Ditugaskan',
            'Terakhir Login',
            'Tanggal Dibuat',
        ];
    }

    public function map($user): array
    {
        $assigned = $user->locations->pluck('location_name')->implode(', ');

        return [
            $user->id,
            $user->name,
            $user->email,
            $user->nik,
            $user->roles->pluck('name')->implode(', '),
            $user->warehouse_id,
            $assigned !== '' ? $assigned : 'Semua gudang',
            $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : 'Belum pernah login',
            $user->created_at ? $user->created_at->format('Y-m-d H:i:s') : '',
        ];
    }
}
