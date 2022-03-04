<?php
namespace timkelty\craftcms\sitesync\models;

use Craft;
use craft\base\Element;
use craft\base\Field;
use craft\events\ModelEvent;
use craft\helpers\ElementHelper;
use timkelty\craftcms\sitesync\Field as SiteSyncField;
use timkelty\craftcms\sitesync\err;

class Syncable extends \craft\base\Model
{
    public $enabled = false;
    public $overwrite = false;
    public $sources = self::SOURCE_ALL;
    public $element;

    const SOURCE_ALL = '*';
    const SOURCE_TITLE = 'title';
    const SOURCE_SLUG = 'slug';
    const SOURCE_FIELDS = 'fields';

    private static function supportedSources(): array
    {
        return [
            self::SOURCE_ALL,
            self::SOURCE_TITLE,
            self::SOURCE_SLUG,
            self::SOURCE_FIELDS,
        ];
    }

    private static function elementSupportsPermissions(Element $element): bool
    {
        return
            $element instanceof \craft\elements\Assets ||
            $element instanceof \craft\elements\Category ||
            $element instanceof \craft\elements\Entry ||
            $element instanceof \craft\elements\Tag ||
            $element instanceof \craft\elements\User;
    }

    public static function beforeElementSaveHandler(ModelEvent $event)
    {
        $element = $event->sender;

        if (!$element->isLocalized() ||
            !$element->validate() ||
            $element->propagating ||
            $event->isNew
        ) {
            err::i()->log(err::bool($element->isLocalized()), '## Syncable::beforeElementSaveHandler(): $element->isLocalized():');
            err::i()->log(err::bool($element->validate()), '## Syncable::beforeElementSaveHandler(): $element->validate():');
            err::i()->log(err::bool($element->propagating), '## Syncable::beforeElementSaveHandler(): $element->propagating:');
            err::i()->log(err::bool($event->isNew), '## Syncable::beforeElementSaveHandler(): $event->isNew:');
            err::i()->log($element->slug, '## Syncable::beforeElementSaveHandler(): # skipping element->slug #');

            return;
        }

        err::i()->log($event, '## Syncable::beforeElementSaveHandler(): $event');

        $syncable = self::findFieldData($element);

        err::i()->log($syncable, '## Syncable::beforeElementSaveHandler(): $syncable');

        if (!$syncable || !$syncable->enabled) {
            err::i()->log('Syncable::beforeElementSaveHandler(): return');

            return;
        }

        // Set element explicily here for when we get field data from an owner
        // element (Entry), but are syncing a child element (Matrix Block)
        $syncable->element = $element;

        err::i()->log($syncable->element->slug, '##updating## Syncable::beforeElementSaveHandler(): $syncable', true);

        $syncable->propagateToSites();
    }

    private static function findFieldData(Element $element): ?Syncable
    {
        $layout = $element->getFieldLayout();

        // No layout (some plugins)
        if (!$layout) {
            return null;
        }

        $fields = array_filter($layout->getFields(), function ($field) {
            return $field instanceof SiteSyncField;
        });
        $field = array_shift($fields);

        // Do owners have a field? (e.g. Matrix blocks)
        if (!$field && method_exists($element, 'getOwner')) {
            return self::findFieldData($element->getOwner());
        }

        // No syncable fields
        if (!$field) {
            return null;
        }

        return $element->getFieldValue($field->handle);
    }

    public function propagateToSites(): array
    {
        $siteIds = $this->getSiteIdsForElement($this->element);

        err::i()->log($siteIds, '##updating## Syncable::beforeElementSaveHandler(): $syncable', true);

        $propagated = array_map(function ($siteId) {
            return $this->propagateToSite($siteId);
        }, $siteIds);

        return array_combine($siteIds, $propagated);
    }

    public function propagateToSite(int $siteId): bool
    {
        if (!$this->enabled ||
            (int)($this->element->siteId) === $siteId ||
            !$this->element->id
        ) {
            return false;
        }

        $siteElement = Craft::$app->getElements()->getElementById($this->element->id, get_class($this->element), $siteId);

        err::i()->log('', '###########################################', true);
        err::i()->log($siteElement->slug, '##updating## Syncable::propagateToSite(): $siteElement->slug', true);

        if (!$siteElement) {
            return false;
        }

        $updates = $this->getUpdatesForElement($siteElement);

        err::i()->log(array_keys($updates["fields"]), '##updating## Syncable::propagateToSite(): array_keys($updates["fields"]', true);
        err::i()->log($updates["title"], '##updating## Syncable::propagateToSite(): array_keys($updates["title"]', true);
        err::i()->log($updates["slug"], '##updating## Syncable::propagateToSite(): array_keys($updates["slug"]', true);
        err::i()->log('', '###########################################', true);

        if (!$updates) {
            return false;
        }

        if (\array_key_exists('fields', $updates)) {
            $siteElement->setFieldValues($updates['fields']);
            unset($updates['fields']);
        }

        //err::i()->log($siteElement, '##updating## Syncable::propagateToSite(): Craft::configure($siteElement, $updates); $siteElement', true);
        err::i()->log(array_keys($updates), '##updating## Syncable::propagateToSite(): Craft::configure($siteElement, array_keys($updates)); $updates', true);
        Craft::configure($siteElement, $updates);

        // Don't bother validating custom fields for other sites
        $siteElement->setScenario(Element::SCENARIO_ESSENTIALS);

        // Prevent recursion
        $siteElement->propagating = true;

        return Craft::$app->elements->saveElement($siteElement, true, false);
    }

    public function beforeValidate()
    {
        $this->sources = $this->sources ?: [];

        return true;
    }

    public function rules()
    {
        $rules = [
            [['enabled', 'overwrite'], 'boolean'],
            [['element'], 'required'],
            [
                'sources',
                'in',
                'range' => self::supportedSources(),
                'allowArray' => true
            ],
        ];

        return $rules;
    }

    private function hasSource(string $source): bool
    {
        return $this->sources === self::SOURCE_ALL || in_array($source, $this->sources);
    }

    private function getUpdatesForElement(Element $siteElement): array
    {
        $savedElement = Craft::$app->getElements()->getElementById($this->element->id, get_class($this->element), (int)($this->element->siteId));
        $updates = [];

        if ($this->hasSource(self::SOURCE_FIELDS)) {
            $updates['fields'] = [];

            foreach ($this->getTranslatableFields($this->element) as $field) {

                // Handle Matrix/SuperTable when overwrite is enabled
                if ($this->overwrite) {
                    if ($field instanceof \craft\fields\Matrix) {
                        Craft::$app->getMatrix()->duplicateBlocks($field, $this->element, $siteElement);
                        continue;
                    } elseif ($field instanceof \verbb\supertable\fields\SuperTableField) {
                        \verbb\supertable\SuperTable::$plugin->getService()->duplicateBlocks($field, $this->element, $siteElement);
                        continue;
                    }
                }

                if ($this->overwrite || $savedElement->getSerializedFieldValues([$field->handle]) === $siteElement->getSerializedFieldValues([$field->handle])) {
                    $updates['fields'][$field->handle] = $this->element->getFieldValue($field->handle);
                }
            }
        }

        if ($this->hasSource(self::SOURCE_TITLE)) {
            if ($this->overwrite || $savedElement->title === $siteElement->title) {
                $updates['title'] = $this->element->title;
            }
        }

        if ($this->hasSource(self::SOURCE_SLUG)) {
            if ($this->overwrite || $savedElement->slug === $siteElement->slug) {
                $updates['slug'] = $this->element->slug;
            }
        }

        return $updates;
    }

    private function getTranslatableFields(Element $element): array
    {
        return array_filter($element->getFieldLayout()->getFields(), function (Field $field) {
            return $field->translationMethod === $field::TRANSLATION_METHOD_SITE;

            // TODO: does this make more sense?
            // return $field->getIsTranslatable();
        });
    }

    /**
     * Some elements (e.g. MatrixBlock) don't have a getIsEditable method, and therefore
     * don't work as expected with ElementHelper::editableSiteIdsForElement.
     * @see https://github.com/craftcms/cms/issues/4116
     */
    private function getSiteIdsForElement(Element $element): array
    {
        if ($this->elementSupportsPermissions($element)) {
            return ElementHelper::editableSiteIdsForElement($element);
        }

        return $element->getSupportedSites();
    }
}
