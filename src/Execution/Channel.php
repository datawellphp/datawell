<?php

declare(strict_types=1);

namespace Datawell\Execution;

/**
 * The conduit a request arrives through (D37). The axis is direct vs delegated,
 * not human vs AI: consumers declare which they are.
 */
enum Channel: string
{
    /** A person acting for themselves — the table UI. */
    case Direct = 'direct';

    /** Something acting on the user's behalf with the user present — the AI chat. */
    case DelegatedInteractive = 'delegatedInteractive';

    /** Something acting on the user's behalf with no one to ask — scheduled automation. */
    case DelegatedNonInteractive = 'delegatedNonInteractive';

    public function isDelegated(): bool
    {
        return $this !== self::Direct;
    }

    public function isInteractive(): bool
    {
        return $this !== self::DelegatedNonInteractive;
    }
}
