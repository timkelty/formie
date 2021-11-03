<?php
namespace verbb\formie\fields\formfields;

use verbb\formie\Formie;
use verbb\formie\base\FormFieldInterface;
use verbb\formie\base\FormFieldTrait;
use verbb\formie\base\RelationFieldTrait;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\helpers\SchemaHelper;

use Craft;
use craft\base\ElementInterface;
use craft\base\Volume;
use craft\elements\Asset;
use craft\fields\Assets as CraftAssets;
use craft\helpers\Assets;
use craft\helpers\Json;
use craft\web\UploadedFile;

use GraphQL\Type\Definition\Type;

class FileUpload extends CraftAssets implements FormFieldInterface
{
    // Traits
    // =========================================================================

    use FormFieldTrait, RelationFieldTrait {
        getFrontEndInputOptions as traitGetFrontendInputOptions;
        getSettingGqlType as traitGetSettingGqlType;
        FormFieldTrait::getIsFieldset insteadof RelationFieldTrait;
        RelationFieldTrait::populateValue insteadof FormFieldTrait;
        RelationFieldTrait::renderLabel insteadof FormFieldTrait;
    }


    // Static Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return Craft::t('formie', 'File Upload');
    }

    /**
     * @inheritDoc
     */
    public static function getSvgIconPath(): string
    {
        return 'formie/_formfields/file-upload/icon.svg';
    }


    // Properties
    // =========================================================================

    /**
     * @var bool
     */
    public $searchable = true;
    public $sizeLimit;
    public $sizeMinLimit;
    public $limitFiles;
    public $restrictFiles;
    public $allowedKinds;
    public $uploadLocationSource;
    public $uploadLocationSubpath;
    public $useSingleFolder = true;

    protected $inputTemplate = 'formie/_includes/element-select-input';


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function init()
    {
        // For Assets field compatibility - we always use a single upload location
        $this->singleUploadLocationSource = $this->uploadLocationSource;
        $this->singleUploadLocationSubpath = $this->uploadLocationSubpath ?? '';
    }

    /**
     * @inheritDoc
     */
    public function beforeSave(bool $isNew): bool
    {
        $this->singleUploadLocationSource = $this->uploadLocationSource;
        $this->singleUploadLocationSubpath = $this->uploadLocationSubpath ?? '';

        return parent::beforeSave($isNew);
    }

    /**
     * @inheritDoc
     */
    public function getValue(ElementInterface $element)
    {
        $values = [];
        foreach ($element->getFieldValue($this->handle)->all() as $asset) {
            /* @var Asset $asset */
            $values[] = $asset->filename;
        }

        return $values;
    }

    /**
     * @inheritDoc
     */
    public function serializeValueForExport($value, ElementInterface $element = null)
    {
        $value = $this->_all($value, $element);

        return array_reduce($value->all(), function($acc, $input) {
            // Handle when volumes don't have a public URL
            return $acc . ($input->url ?? $input->filename);
        }, '');
    }

    /**
     * @inheritDoc
     */
    public function serializeValueForIntegration($value, ElementInterface $element = null)
    {
        return array_map(function($input) {
            // Handle when volumes don't have a public URL
            return $input->url ?? $input->filename;
        }, $this->_all($value, $element)->all());
    }

    /**
     * @inheritDoc
     */
    public function serializeValueForWebhook($value, ElementInterface $element = null)
    {
        $values = [];
        foreach ($element->getFieldValue($this->handle)->all() as $asset) {
            /* @var Asset $asset */
            $values[] = $asset->toArray();
        }

        return $values;
    }

    /**
     * @inheritDoc
     */
    public function getExtraBaseFieldConfig(): array
    {
        return [
            'volumes' => $this->getVolumeOptions(),
            'fileKindOptions' => $this->getFileKindOptions(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getFieldDefaults(): array
    {
        $settings = Formie::$plugin->getSettings();

        $volume = $settings->defaultFileUploadVolume;
        $volumes = Craft::$app->getVolumes()->getAllVolumes();

        if (!$volume && !empty($volumes)) {
            $volume = 'folder:' . $volumes[0]->uid;
        }

        return [
            'uploadLocationSource' => $volume,
            'uploadLocationSubpath' => '',
            'restrictFiles' => true,
            'allowedKinds' => [
                'image',
                'pdf',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function getElementValidationRules(): array
    {
        $rules = parent::getElementValidationRules();
        $rules[] = 'validateFileLimit';
        $rules[] = 'validateMinFileSize';
        $rules[] = 'validateMaxFileSize';

        return $rules;
    }

    /**
     * Validates number of files selected.
     *
     * @param ElementInterface $element
     */
    public function validateFileLimit(ElementInterface $element)
    {
        $fileLimit = intval($this->limitFiles ?? 1);

        $value = $element->getFieldValue($this->handle);
        $count = $value->count();

        // TODO: fix, doesn't work.
        if ($count > $fileLimit) {
            $element->addError(
                $this->handle,
                Craft::t('formie', 'Choose up to {files} files.', [
                    'files' => $fileLimit
                ])
            );
        }
    }

    /**
     * Validates the files to make sure they are under the allowed max file size.
     *
     * @param ElementInterface $element
     */
    public function validateMaxFileSize(ElementInterface $element)
    {
        $filenames = [];

        // Get any uploaded filenames
        $uploadedFiles = $this->_getUploadedFiles($element);
        
        if ($this->sizeLimit) {
            $sizeLimit = $this->sizeLimit * 1000000;

            foreach ($uploadedFiles as $file) {
                if (file_exists($file['location']) && (filesize($file['location']) > $sizeLimit)) {
                    $filenames[] = $file['filename'];
                }
            }
        }

        foreach ($filenames as $filename) {
            $element->addError($this->handle, Craft::t('formie', 'File must be smaller than {size} MB.', [
                'size' => $this->sizeLimit,
            ]));
        }
    }

    /**
     * Validates the files to make sure they are over the allowed min file size.
     *
     * @param ElementInterface $element
     */
    public function validateMinFileSize(ElementInterface $element)
    {
        $filenames = [];

        // Get any uploaded filenames
        $uploadedFiles = $this->_getUploadedFiles($element);
        
        if ($this->sizeMinLimit) {
            $sizeMinLimit = $this->sizeMinLimit * 1000000;

            foreach ($uploadedFiles as $file) {
                if (file_exists($file['location']) && (filesize($file['location']) < $sizeMinLimit)) {
                    $filenames[] = $file['filename'];
                }
            }
        }

        foreach ($filenames as $filename) {
            $element->addError($this->handle, Craft::t('formie', 'File must be larger than {size} MB.', [
                'size' => $this->sizeMinLimit,
            ]));
        }
    }

    /**
     * @inheritDoc
     */
    public function getPreviewInputHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('formie/_formfields/file-upload/preview', [
            'field' => $this,
        ]);
    }

    /**
     * Returns a comma separated list of allowed file extensions
     * that are allowed to be uploaded.
     *
     * @return string|null
     */
    public function getAccept()
    {
        if (!$this->restrictFiles) {
            return null;
        }

        $extensions = [];
        $allKinds = Assets::getAllowedFileKinds();

        foreach ($this->allowedKinds as $allowedKind) {
            $kind = $allKinds[$allowedKind];

            foreach ($kind['extensions'] as $extension) {
                $extensions[] = ".$extension";
            }
        }

        return implode(', ', $extensions);
    }

    public function getVolumeOptions()
    {
        $volumes = [];

        /* @var Volume $volume */
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $volumes[] = [
                'label' => $volume->name,
                'value' => 'folder:' . $volume->uid,
            ];
        }

        return $volumes;
    }

    /**
     * @inheritdoc
     */
    public function getFrontEndJsModules()
    {
        return [
            'src' => Craft::$app->getAssetManager()->getPublishedUrl('@verbb/formie/web/assets/frontend/dist/js/fields/file-upload.js', true),
            'module' => 'FormieFileUpload',
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineGeneralSchema(): array
    {
        return [
            SchemaHelper::labelField(),
            [
                'label' => Craft::t('formie', 'Upload Location'),
                'help' => Craft::t('formie', 'Note that the subfolder path can contain variables like {myFieldHandle}.'),
                'type' => 'fieldWrap',
                'children' => [
                    [
                        'component' => 'div',
                        'class' => 'flex',
                        'children' => [
                            SchemaHelper::selectField([
                                'name' => 'uploadLocationSource',
                                'options' => $this->getVolumeOptions(),
                            ]),
                            SchemaHelper::textField([
                                'name' => 'uploadLocationSubpath',
                                'class' => 'text flex-grow',
                                'placeholder' => 'path/to/subfolder',
                            ]),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineSettingsSchema(): array
    {
        $configLimit = Craft::$app->getConfig()->getGeneral()->maxUploadFileSize;
        $phpLimit = (max((int)ini_get('post_max_size'), (int)ini_get('upload_max_filesize'))) * 1048576;
        $maxUpload = $this->humanFilesize(max($phpLimit, $configLimit));

        return [
            SchemaHelper::lightswitchField([
                'label' => Craft::t('formie', 'Required Field'),
                'help' => Craft::t('formie', 'Whether this field should be required when filling out the form.'),
                'name' => 'required',
            ]),
            SchemaHelper::toggleContainer('settings.required', [
                SchemaHelper::textField([
                    'label' => Craft::t('formie', 'Error Message'),
                    'help' => Craft::t('formie', 'When validating the form, show this message if an error occurs. Leave empty to retain the default message.'),
                    'name' => 'errorMessage',
                ]),
            ]),
            SchemaHelper::textField([
                'label' => Craft::t('formie', 'Limit Number of Files'),
                'help' => Craft::t('formie', 'Limit the number of files a user can upload.'),
                'name' => 'limitFiles',
                'size' => '3',
                'class' => 'text',
                'validation' => 'optional|number|min:0',
            ]),
            SchemaHelper::textField([
                'label' => Craft::t('formie', 'Min File Size'),
                'help' => Craft::t('formie', 'Set the minimum size of the files a user can upload.'),
                'name' => 'sizeMinLimit',
                'size' => '3',
                'class' => 'text',
                'type' => 'textWithSuffix',
                'suffix' => Craft::t('formie', 'MB'),
                'validation' => 'optional|number|min:0',
            ]),
            SchemaHelper::textField([
                'label' => Craft::t('formie', 'Max File Size'),
                'help' => Craft::t('formie', 'Set the maxiumum size of the files a user can upload.'),
                'name' => 'sizeLimit',
                'size' => '3',
                'class' => 'text',
                'type' => 'textWithSuffix',
                'suffix' => Craft::t('formie', 'MB'),
                'validation' => 'optional|number|min:0',
                'warning' => Craft::t('formie', 'Maximum allowed upload size is {size}.', ['size' => $maxUpload]),
            ]),
            SchemaHelper::checkboxField([
                'label' => Craft::t('formie', 'Restrict allowed file types?'),
                'name' => 'restrictFiles',
            ]),
            SchemaHelper::toggleContainer('settings.restrictFiles', [
                SchemaHelper::checkboxField([
                    'name' => 'allowedKinds',
                    'options' => $this->getFileKindOptions(),
                ]),
            ]),
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineAppearanceSchema(): array
    {
        return [
            SchemaHelper::visibility(),
            SchemaHelper::labelPosition($this),
            SchemaHelper::instructions(),
            SchemaHelper::instructionsPosition($this),
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineAdvancedSchema(): array
    {
        return [
            SchemaHelper::handleField(),
            SchemaHelper::cssClasses(),
            SchemaHelper::containerAttributesField(),
            SchemaHelper::inputAttributesField(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineConditionsSchema(): array
    {
        return [
            SchemaHelper::enableConditionsField(),
            SchemaHelper::conditionsField(),
        ];
    }

    /**
     * @inheritDoc
     */
    protected function getSettingGqlType($attribute, $type, $fieldInfo)
    {
        if ($attribute === 'allowedKinds') {
            return [
                'name' => $attribute,
                'type' => Type::listOf(Type::string()),
            ];
        }

        return $this->traitGetSettingGqlType($attribute, $type, $fieldInfo);
    }


    // Private Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    private function humanFilesize($size, $precision = 2)
    {
        for ($i = 0; ($size / 1024) > 0.9; $i++, $size /= 1024) {}
        return round($size, $precision).['B','kB','MB','GB','TB','PB','EB','ZB','YB'][$i];
    }

    /**
     * Returns any files that were uploaded to the field.
     *
     * @param ElementInterface $element
     * @return array
     */
    private function _getUploadedFiles(ElementInterface $element): array
    {
        $uploadedFiles = [];

        // See if we have uploaded file(s).
        $paramName = $this->requestParamName($element);

        if ($paramName !== null) {
            $files = UploadedFile::getInstancesByName($paramName);

            foreach ($files as $file) {
                $uploadedFiles[] = [
                    'filename' => $file->name,
                    'location' => $file->tempName,
                    'type' => 'upload',
                ];
            }
        }

        return $uploadedFiles;
    }
}
