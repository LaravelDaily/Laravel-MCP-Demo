@props(['status'])

<flux:badge :color="match ($status) {
    \App\Enums\TaskStatus::Todo => 'yellow',
    \App\Enums\TaskStatus::InProgress => 'blue',
    \App\Enums\TaskStatus::Done => 'green',
}" inset {{ $attributes }}>{{ $status->value }}</flux:badge>
