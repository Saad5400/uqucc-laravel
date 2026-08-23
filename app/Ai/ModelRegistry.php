<?php

namespace App\Ai;

use Saad\AiKit\Catalog\Catalog;

/**
 * The ONE place this app decides which model a task is sent to.
 *
 * Every AI surface — the student assistant, the admin assistant, the page
 * copilot, and all three vision extractors — resolves through here, so the
 * surfaces cannot drift apart and a model change is a one-line change.
 *
 * The chain is exactly two links deep and BOTH are config:
 *
 *   1. this app's own `config('ai.*')` key, when it is non-empty; and
 *   2. the fleet's shared default from the kit (ai-kit docs/DECISIONS.md #26).
 *
 * There is deliberately no third link. Until 2026-08-24 an `AiSettings` row in
 * the database sat ABOVE config and silently won, which meant a deploy could
 * change the configured model and change nothing at all — the app kept
 * answering students on the old one. Ruling #26 deleted those rows. If a model
 * needs to change, it changes in the kit (for the whole fleet) or in this
 * app's config (for this app); never at runtime, never invisibly.
 *
 * uqucc's app-level keys ship EMPTY on purpose, so the fleet default applies
 * and there is no second copy of a slug to keep in sync.
 */
class ModelRegistry
{
    public function __construct(private readonly Catalog $catalog) {}

    /**
     * The conversational model every assistant surface sends to.
     */
    public function chat(): string
    {
        return $this->configured('ai.chat.model') ?? $this->catalog->chatModel();
    }

    /**
     * How hard the assistant thinks. Shared with the fleet: reply quality is
     * what makes the assistant usable for real students, not a local taste.
     */
    public function chatReasoningEffort(): string
    {
        return $this->configured('ai.chat.reasoning_effort') ?? $this->catalog->chatReasoningEffort();
    }

    /**
     * The "eyes only" model that turns an uploaded image or scan into text.
     *
     * Necessarily a different model from {@see chat()}: the fleet's chat
     * default is text-only on OpenRouter, so an image routed at it fails
     * rather than degrades.
     */
    public function vision(): string
    {
        return $this->configured('ai.vision.model') ?? $this->catalog->visionModel();
    }

    /**
     * The heavyweight authoring model for admin-triggered, review-gated work
     * (drafting a page from a document, proposing revisions). App-level on
     * purpose — no other app in the fleet has this surface.
     */
    public function authoring(): string
    {
        return $this->configured('ai.authoring.model') ?? $this->chat();
    }

    /**
     * A config value, or null when it is unset or blank — an empty string in
     * config means "inherit", not "route to a model with no name".
     */
    private function configured(string $key): ?string
    {
        $value = trim((string) config($key, ''));

        return $value !== '' ? $value : null;
    }
}
