<?php

namespace Solarise\SmartTriage\Taxonomies;

/**
 * Anything that can name a set of options for a choice question.
 *
 * The contract is deliberately one method. A taxonomy is a list of names with
 * descriptions; where those come from — a config array, a PHP enum, a table of
 * categories — is not the triage's business.
 */
interface Taxonomy
{
    /**
     * @return array<string, string|array|null>  option name => description, or null where the name says it
     */
    public function options(): array;

    /**
     * A stable identifier for this taxonomy's current contents, so a changed
     * taxonomy can invalidate the answers it produced.
     */
    public function fingerprint(): string;
}
