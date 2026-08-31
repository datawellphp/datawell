<?php

declare(strict_types=1);

namespace Datawell\Result;

use Datawell\Actions\Action;
use Datawell\Actions\ClientAction;
use Datawell\Actions\LinkAction;
use Datawell\DataSource;
use Datawell\Definition;
use Datawell\Execution\Context;
use Datawell\Fields\Field;

/**
 * Rows through visible fields only (D18), entity references for enum/relation values (D21),
 * self-links from the representation (D34), and the per-row actions map (D37, D43).
 */
class Serializer
{
    /**
     * @param  array<string, Field>  $fields  the fields to emit — visible, and selected if the request selected
     * @param  array<string, Action>  $actions  the actions visible to this user on this channel
     * @return array<string, mixed>
     */
    public function row(object $row, DataSource $source, Definition $definition, array $fields, array $actions, Context $context, string $keyName): array
    {
        $id = data_get($row, $keyName);
        $representation = $source->representation();
        $serialized = ['id' => $id];

        if ($representation->hasUrl()) {
            $url = $representation->urlFor($row);

            if ($url !== null) {
                $serialized['url'] = $url;
            }
        }

        $serialized['actions'] = $this->actionsFor($row, $actions, $context);

        foreach ($fields as $key => $field) {
            $serialized[$key] = $field->serialize($row, $context);
        }

        return $serialized;
    }

    /**
     * The entity's calling card (D21), for lookups and references.
     */
    public function ref(object $row, DataSource $source, string $keyName): EntityRef
    {
        return $source->representation()->refFor($row, $keyName);
    }

    /**
     * Per-row action resolutions: link → url, client → payload, server → true; an action
     * the user may not perform on this row is simply absent (hidden means absent, per row).
     *
     * @param  array<string, Action>  $actions
     * @return array<string, mixed>
     */
    protected function actionsFor(object $row, array $actions, Context $context): array
    {
        $map = [];

        foreach ($actions as $key => $action) {
            if ($action->isStandalone() || ! $action->authorizes($context->user, $row)) {
                continue;
            }

            $map[$key] = match (true) {
                $action instanceof LinkAction => $action->urlFor($row),
                $action instanceof ClientAction => $action->payloadFor($row),
                default => true,
            };

            if ($map[$key] === null) {
                unset($map[$key]);
            }
        }

        return $map;
    }
}
