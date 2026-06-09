<?php

namespace craft\anchors;

use Craft;
use craft\base\Component;
use craft\helpers\ArrayHelper;
use craft\helpers\Html;
use craft\helpers\StringHelper;

/**
 * Class Parser
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Parser extends Component
{
    // Properties
    // =========================================================================

    /**
     * @var string|null
     */
    public $anchorClass;

    /**
     * @var string|null Where the anchor link should be positioned within the heading, relative to the heading text (`before` or `after`)
     * @since 2.3.0
     */
    public $anchorLinkPosition;

    /**
     * @var string|null
     */
    public $anchorLinkClass;

    /**
     * @var string|null
     */
    public $anchorLinkText;

    /**
     * @var string|null
     */
    public $anchorLinkTitleText;

    /**
     * @var bool|null
     * @since 3.3.0
     */
    public $useAdditionalTagToAnchorTo;

    /**
     * @var array Tracks generated IDs to ensure uniqueness.
     */
    private array $generatedIds = [];

    // Public Methods
    // =========================================================================

    /**
     * Parses some HTML for headings and adds anchor links to them.
     *
     * @param string $html The HTML to parse
     * @param string|string[] $tags The tags to add anchor links to.
     * @param string|null $language The content language, used when converting non-ASCII characters to ASCII
     * @param bool $lowercase Whether to always lowercase the entire anchor name
     * @return string The parsed HTML.
     */
    public function parseHtml(string $html, $tags = 'h1,h2,h3', ?string $language = null, bool $lowercase = false): string
    {
        if (is_string($tags)) {
            $tags = StringHelper::split($tags);
        }

        $this->generatedIds = [];

        return preg_replace_callback('/<(' . implode('|', $tags) . ')([^>]*)>\s*([\w\W]+?)\s*<\/\1>/', function(array $match) use ($language, $lowercase) {
            $headingHasId = false;
            // try to get id from the heading tag only if we're not supposed to use additional tag to anchor to
            if (!$this->useAdditionalTagToAnchorTo && !empty($match[2])) {
                $anchorName = $this->getIdFromHeading($match[2]);
                if (!empty($anchorName)) {
                    $headingHasId = true;
                    $this->generatedIds[$anchorName] = true;
                }
            }

            // if we still don't have the name for the anchor - generate it
            if (empty($anchorName)) {
                $anchorName = $this->generateAnchorName($match[3], $language, $lowercase);
            }

            $heading = preg_replace('/\s+/', ' ', strip_tags(str_replace(['&nbsp;', ' '], ' ', $match[3])));
            $link = Html::tag('a', $this->anchorLinkText, [
                'class' => $this->anchorLinkClass,
                'title' => Craft::t('anchors', $this->anchorLinkTitleText, ['heading' => $heading]),
                'aria-label' => Craft::t('anchors', $this->anchorLinkTitleText, ['heading' => $heading]),
                'href' => "#$anchorName",
            ]);

            return
                ($this->useAdditionalTagToAnchorTo ?
                    Html::tag('span', '', [
                        'class' => $this->anchorClass,
                        'id' => $anchorName,
                    ]) : '') .
                "<$match[1]$match[2]" . (!$this->useAdditionalTagToAnchorTo && !$headingHasId ? " id=\"$anchorName\"" : "") . ">" .
                ($this->anchorLinkPosition === Settings::POS_BEFORE ? "$link $match[3]" : "$match[3] $link") .
                "</$match[1]>";
        }, $html);
    }

    /**
     * Generates an anchor name based on a given heading.
     *
     * @param string $heading
     * @param string|null $language
     * @param bool $lowercase
     * @return string The generated anchor name.
     */
    public function generateAnchorName(string $heading, string $language = null, bool $lowercase = false): string
    {
        // decode html entities into chars
        // see https://github.com/craftcms/anchors/issues/31 for details
        $heading = htmlspecialchars_decode($heading, ENT_QUOTES);

        // Remove HTML tags
        $heading = preg_replace('/<(.*?)>/', '', $heading);

        // Remove parentheses
        $heading = preg_replace('/\(.*?\)/', '', $heading);

        // Remove inner-word punctuation
        $heading = preg_replace('/[\'"‘’“”]/u', '', $heading);

        // Convert non-breaking spaces to spaces
        $heading = str_replace(['&nbsp;', ' '], ' ', $heading);

        // Get the "words". This will search for any unicode "letters" or "numbers"
        preg_match_all('/[\p{L}\p{N}]+/u', $heading, $words);
        $words = ArrayHelper::filterEmptyStringsFromArray($words[0]);

        // Turn them into camelCase
        foreach ($words as $i => $word) {
            if ($lowercase === true) {
                $words[$i] = strtolower($word);
            } else {
                // Special case if the whole word is capitalized
                if (strtoupper($word) === $word) {
                    $words[$i] = strtolower($word);
                } else {
                    $words[$i] = lcfirst($word);
                }
            }
        }

        // Put them together as the anchor name
        $anchorName = StringHelper::toAscii(implode('-', $words), $language);

        // Ensure uniqueness of anchor name by appending a number if necessary
        $uniqueName = $this->getUniqueAnchorName($anchorName);
        $this->generatedIds[$uniqueName] = true;

        return $uniqueName;
    }

    private function getUniqueAnchorName(string $anchorName): string
    {
        if (!isset($this->generatedIds[$anchorName])) {
            return $anchorName;
        }

        // Index starts at 2 because the first duplicate would be "name-2", similar to how duplicate slugs are handled in Craft.
        $i = 2;
        while (isset($this->generatedIds["$anchorName-$i"])) {
            $i++;
        }

        return "$anchorName-$i";
    }

    /**
     * Check if there's an id in the attributes. If there is one - return its value.
     *
     * @param $attributes
     * @return string|null
     */
    public function getIdFromHeading($attributes): ?string
    {
        $id = null;
        preg_match('/id="(\w+)"/', $attributes, $match);

        if (isset($match[1]) && !empty($match[1])) {
            $id = trim($match[1]);
        }

        return $id;
    }
}
