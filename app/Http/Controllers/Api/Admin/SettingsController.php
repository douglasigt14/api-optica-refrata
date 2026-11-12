<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Configurações da ótica (12/11/2026). A primeira: a logo das impressões (sai no cupom da térmica). Admin e atendente
 * leem (o atendente imprime); só o admin troca ou tira. Disco privado, link assinado (`print_logo_url`).
 */
class SettingsController extends Controller
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function show(): JsonResponse
    {
        return $this->settings($this->current->require());
    }

    /** Uma logo só: a nova substitui a anterior. */
    public function storePrintLogo(Request $request): JsonResponse
    {
        $request->validate(
            ['logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']],
            [
                'logo.required' => 'Escolha a logo.',
                'logo.image' => 'A logo precisa ser uma imagem (JPG, PNG ou WebP).',
                'logo.mimes' => 'A logo precisa ser JPG, PNG ou WebP.',
                'logo.max' => 'A logo pode ter até 5 MB.',
            ],
        );
        $company = $this->current->require();
        $old = $company->print_logo_path;
        $company->update(['print_logo_path' => $request->file('logo')->store("companies/{$company->id}/settings", 'local')]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return $this->settings($company);
    }

    public function destroyPrintLogo(): JsonResponse
    {
        $company = $this->current->require();
        if ($company->print_logo_path) {
            Storage::disk('local')->delete($company->print_logo_path);
            $company->update(['print_logo_path' => null]);
        }

        return $this->settings($company);
    }

    /** A logo pelo link assinado de `print_logo_url` (a tag de imagem não manda o token). */
    public function printLogo(Company $company): StreamedResponse
    {
        $path = $company->print_logo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, headers: ['Cache-Control' => 'private, max-age=86400']);
    }

    private function settings(Company $company): JsonResponse
    {
        return response()->json(['data' => ['print_logo_url' => $company->printLogoUrl()]]);
    }
}
