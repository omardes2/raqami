<?php

namespace App\Modules\Localization\Services;

/** Localization foundation (ADR-012): supported locales & text direction. */
class LocaleService
{
    public function supported(): array
    {
        return config('localization.supported', ['en']);
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supported(), true);
    }

    /** 'rtl' or 'ltr' — derived from config, never hard-coded per page. */
    public function direction(string $locale): string
    {
        return in_array($locale, config('localization.rtl', []), true) ? 'rtl' : 'ltr';
    }

    /** @return array<int,array{code:string,name:string,direction:string}> */
    public function catalog(): array
    {
        $names = config('localization.names', []);

        return array_map(fn (string $code) => [
            'code' => $code,
            'name' => $names[$code] ?? $code,
            'direction' => $this->direction($code),
        ], $this->supported());
    }
}
