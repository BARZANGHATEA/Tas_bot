<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Support\Facades\Auth;

abstract class AdminController extends Controller
{
    protected function admin(): Admin
    {
        return Auth::guard('admin')->user();
    }

    /** Re-authentication required for financial and sensitive actions. */
    protected function confirmRules(): array
    {
        return ['confirm_password' => ['required', 'string', 'current_password:admin']];
    }

    /** Neutralise spreadsheet formula injection in CSV exports. */
    protected function csvCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    protected function csv(string $filename, array $header, iterable $rows): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => $this->csvCell($v), $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
