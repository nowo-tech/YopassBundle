<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Ui;

/**
 * Supported CSS stacks for the manage Web UI (REQ-UI-001).
 */
enum CssFramework: string
{
    case Bootstrap = 'bootstrap';

    case Bootstrap4 = 'bootstrap4';

    case Bootstrap5 = 'bootstrap5';

    case Tailwind = 'tailwind';

    case Foundation = 'foundation';

    case Custom = 'custom';

    case Tabler = 'tabler';

    case None = 'none';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function loadsBootstrapCompatibleCdn(): bool
    {
        return match ($this) {
            self::Bootstrap, self::Bootstrap5, self::Tabler => true,
            default                                         => false,
        };
    }

    public function loadsBootstrap4Cdn(): bool
    {
        return $this === self::Bootstrap4;
    }
}
