<?php

namespace App\Concerns;

trait ProjectValidationRules
{
    /**
     * Validation for project creation and editing. The name is expected to
     * be trimmed by the caller before validation.
     *
     * @return array<string, array<int, string>>
     */
    public function projectRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'instructions' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
