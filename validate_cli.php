<?php
/**
 * @license
 * Copyright 2018 TruongLuu. All Rights Reserved.
 */

$rootDir = __DIR__;
require ($rootDir . '/src/XF.php');
XF::start($rootDir);

array_shift($argv);
$addOnId = array_shift($argv);

if (!is_dir(\XF::getAddOnDirectory() . '/' . $addOnId)) {
    throw new \RuntimeException('Add-on (' , $addOnId . ') not exists.');
}

$phrase = new PhraseValidator($addOnId);
$template = new TemplateValidator($addOnId);

$isOk = $phrase->isOk() && $template->isOk();
if (!$isOk) {
    echo 'Please fix all above issues.' . PHP_EOL;
    exit(1);
}

class TextColor {
    protected $textColors = [];
    protected $bgColors = [];

    protected $textColor;
    protected $bgColor;
    protected $text;

    public function __construct($text, $textColor = null, $bgColor = null)
    {
        $this->textColors['black'] = '0;30';
        $this->textColors['dark_gray'] = '1;30';
        $this->textColors['blue'] = '0;34';
        $this->textColors['light_blue'] = '1;34';
        $this->textColors['green'] = '0;32';
        $this->textColors['light_green'] = '1;32';
        $this->textColors['cyan'] = '0;36';
        $this->textColors['light_cyan'] = '1;36';
        $this->textColors['red'] = '0;31';
        $this->textColors['light_red'] = '1;31';
        $this->textColors['purple'] = '0;35';
        $this->textColors['light_purple'] = '1;35';
        $this->textColors['brown'] = '0;33';
        $this->textColors['yellow'] = '1;33';
        $this->textColors['light_gray'] = '0;37';
        $this->textColors['white'] = '1;37';

        $this->bgColors['black'] = '40';
        $this->bgColors['red'] = '41';
        $this->bgColors['green'] = '42';
        $this->bgColors['yellow'] = '43';
        $this->bgColors['blue'] = '44';
        $this->bgColors['magenta'] = '45';
        $this->bgColors['cyan'] = '46';
        $this->bgColors['light_gray'] = '47';

        $this->text = $text;
        $this->textColor = $textColor;
        $this->bgColor = $bgColor;
    }

    public function render()
    {
        $rendered = "";

        if (isset($this->textColors[$this->textColor])) {
            $rendered .= "\033[" . $this->textColors[$this->textColor] . "m";
        }

        if (isset($this->bgColors[$this->bgColor])) {
            $rendered .= "\033[" . $this->bgColors[$this->bgColor] . "m";
        }

        $rendered .= $this->text . "\033[0m";
        return $rendered;
    }

    public function __toString()
    {
        return $this->render();
    }
}

abstract class AbstractValidator {
    protected $addOnId;
    protected $addOnDir;

    protected $errors = [];
    // all files inside the add-on
    protected $files = [];

    public function __construct($addOnId)
    {
        $this->addOnId = $addOnId;
        $this->addOnDir = \XF::getAddOnDirectory() . '/' . $addOnId;

        $this->files = $this->getFiles($this->addOnDir);
    }

    /**
     * @return bool
     */
    abstract public function isOk();

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    protected function relativePath($path)
    {
        return substr($path, strlen(\XF::getRootDirectory()) + 1);
    }

    protected function output($message, $color = null, $indent = 0)
    {
        if ($color) {
            if (is_array($color)) {
                $message = new TextColor($message, $color[0], $color[1]);
            } else {
                $message = new TextColor($message, $color);
            }
        }
        
        echo str_repeat(' ', $indent) . $message . PHP_EOL;
    }

    protected function getFiles($path)
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $handle = opendir($path);

        while (($fileOrDir = readdir($handle)) !== false) {
            if ($fileOrDir === '.' || $fileOrDir === '..') {
                continue;
            }

            $fileOrDir = $path . '/' . $fileOrDir;
            if (is_dir($fileOrDir)) {
                $files = array_merge($files, $this->getFiles($fileOrDir));
            } else {
                $files[] = $fileOrDir;
            }
        }

        closedir($handle);

        return $files;
    }

    protected function getLine($content, $offset, $lineSeparator = "\n")
    {
        return substr_count(substr($content, 0, $offset), $lineSeparator) + 1;
    }
}

class PhraseValidator extends AbstractValidator {
    // array contain phrases created in database but not used in anywhere.
    protected $notUsed = [];
    // array contain phrases used in templates, code but not found in database
    protected $notFound = [];
    // array contain phrases which shared from another add-ons
    protected $shared = [];
    // array contain phrases used in templates, code, etc...
    protected $usedPhrases = [];
    // array contain phrases of this add-on in database
    protected $phrases = [];

    public function isOk()
    {
        $this->collectUsedPhrases();
        $this->loadAddOnPhrases();

        foreach ($this->usedPhrases as $phraseId => $matches) {
            if (isset($this->phrases[$phraseId])) {
                continue;
            }

            if (strpos($phraseId, '*') !== false) {
                // allow wildcard phraseId. Eg: test_phrase_wildcard_*
                $wildcardPhraseId = str_replace('*', '[a-z0-9_\.]+', $phraseId);

                $foundPhrase = null;

                foreach ($this->phrases as $phrase) {
                    $pattern = '/^'. $wildcardPhraseId .'$/i';
                    if (preg_match($pattern, $phrase->title)) {
                        $foundPhrase = $phrase;
                        break;
                    }
                }

                if ($foundPhrase) {
                    continue;
                }
            }

            /** @var \XF\Entity\Phrase $phrase */
            $phrase = \XF::finder('XF:Phrase')
                ->where('title', $phraseId)
                ->fetchOne();

            if (!$phrase) {
                $this->notFound[$phraseId] = $matches;
            } elseif ($phrase->addon_id !== 'XF' && $phrase->addon_id !== $this->addOnId) {
                $this->shared[$phraseId] = [$phraseId, $phrase->addon_id];
            }
        }

        /** @var \XF\Entity\Phrase $phrase */
        foreach ($this->phrases as $phrase) {
            if (!isset($this->usedPhrases[$phrase->title])
                && !$this->isValid($phrase->title)
            ) {
                $this->notUsed[] = $phrase->title;
            }
        }
        
        $this->printErrors();

        if ($this->notUsed || $this->notFound) {
            return false;
        }

        return true;
    }

    protected function printErrors()
    {
        if ($this->notFound) {
            $this->output( 'Phrases not found:');
            foreach ($this->notFound as $phraseId => $usedFiles) {
                $this->output($phraseId, ['white', 'red'], 2);
                foreach ($usedFiles as $path => $lines) {
                    foreach ($lines as $lineNumber) {
                        $this->output(
                            'Used in: ' . $this->relativePath($path) . '::' . $lineNumber,
                            33,
                            4
                        );
                    }
                }
            }
        }

        if ($this->notUsed) {
            $this->output( 'Phrases not used any where:');
            foreach ($this->notUsed as $phraseId) {
                $this->output($phraseId, ['white', 'red'], 2);
            }
        }

        if ($this->shared) {
            $this->output('Phrases shared by add-on:');
            foreach ($this->shared as $phrase) {
                $this->output($phrase[0] . ' - ' . $phrase[1], 'green', 2);
            }
        }
    }

    protected function collectUsedPhrases()
    {
        $files = $this->files;
        $phraseMap = [];

        foreach ($files as $path) {
            if (strpos($path, '/_data/') !== false) {
                // ignore all files in _data folder to prevent
                // used in old template data.
                continue;
            }

            $content = file_get_contents($path);
            preg_match_all(
                '/(phrase|XF::phrase)\((\'|")([a-z0-9\._\*]+)([\.,:]?)(\'|")?/i',
                $content,
                $matches,
                PREG_OFFSET_CAPTURE
            );

            if (empty($matches[3])) {
                continue;
            }

            foreach ($matches[3] as $match) {
                $phraseId = $this->normalizePhraseId($match[0]);
                $phraseMap[$phraseId][$path][] = $this->getLine($content, $match[1]);
            }
        }

        $this->usedPhrases = $phraseMap;
    }

    protected function normalizePhraseId($phraseId)
    {
        if (substr($phraseId, -3) === '...') {
            $phraseId = substr($phraseId, 0, strlen($phraseId) - 3);
        } elseif (in_array(substr($phraseId, -1), [',', ':', ')', '('])) {
            $phraseId = substr($phraseId, 0, strlen($phraseId) - 1);
        }

        return $phraseId;
    }

    protected function loadAddOnPhrases()
    {
        /** @var \XF\Finder\Phrase $finder */
        $finder = \XF::finder('XF:Phrase');
        $this->phrases = $finder->fromAddOn($this->addOnId)->fetch();
    }

    protected function isValid($phraseId)
    {
        $validPrefixes = [
            'permission.',
            'permission_interface.',

            'nav.',

            'option.',
            'option_explain.',

            'widget_def.',
            'widget_def_desc.',

            'admin_navigation.',

            'option_group.',
            'option_group_description.',

            'cron_entry.'
        ];

        foreach ($validPrefixes as $prefix) {
            if (strpos($phraseId, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}

class TemplateValidator extends AbstractValidator {
    protected $templateFile;

    protected $notFound = [];
    protected $notSynced = [];
    protected $shared = [];

    protected $recommendWildcards = [];

    protected $usedTemplates = [];
    protected $templates = [];

    public function __construct($addOnId)
    {
        parent::__construct($addOnId);

        $this->templateFile = $this->addOnDir . '/_data/templates.xml';
    }

    public function isOk()
    {
        $this->collectUsedTemplates();
        $this->loadAddOnTemplates();

        $xml = \XF\Util\Xml::openFile($this->templateFile);
        $xmlTemplates = [];

        foreach ($xml->template as $xmlTemplate) {
            $type = (string) $xmlTemplate['type'];
            $title = (string) $xmlTemplate['title'];
            $content = (string) $xmlTemplate;

            $xmlTemplates[$this->getTemplateKey($type, $title)] = $content;
        }

        foreach ($this->usedTemplates as $templateKey => $matches) {
            list($type, $templateId) = explode(':', $templateKey);

            if (strpos($templateId, '*') !== false) {
                $wildcardTemplateId = str_replace('*', '[a-z0-9_\.]+', $templateId);
                $pattern = '/^'. $wildcardTemplateId .'$/i';

                $foundTemplate = null;

                /** @var \XF\Entity\Template $templateEntity */
                foreach ($this->templates as $templateEntity) {
                    if (preg_match($pattern, $templateEntity->title)) {
                        $foundTemplate = $templateEntity;
                        break;
                    }
                }

                if ($foundTemplate) {
                    continue;
                }
            }

            /** @var \XF\Entity\Template $templateEntity */
            $templateEntity = \XF::finder('XF:Template')
                    ->where('title', $templateId)
                    ->where('type', $type)
                    ->fetchOne();

            if ($templateEntity === null) {
                $this->notFound[$templateKey][] = $matches;

                continue;
            }

            if ($templateEntity->addon_id !== $this->addOnId) {
                $this->shared[] = [$type, $templateId, $templateEntity->addon_id];

                continue;
            }
        }

        foreach ($xmlTemplates as $templateKey => $content) {
            list($type, $templateId) = explode(':', $templateKey);

            $finalContent = $this->getDevTemplateContent($type, $templateId);
            if ($finalContent !== $content) {
                $this->notSynced[$this->getTemplateKey($type, $templateId)] = true;
            }
        }

        $this->printErrors();

        if ($this->notFound || $this->notSynced) {
            return false;
        }

        return true;
    }

    protected function printErrors()
    {
        if ($this->notFound) {
            $this->output('Templates not found:');
            foreach ($this->notFound as $template => $paths) {
                $this->output($template, ['white', 'red'], 2);

                foreach ($paths as $lines) {
                    foreach ($lines as $line) {
                        $this->output(
                            'Used in: ' . $this->relativePath($line[0]) . '::' . $line[1],
                            'green',
                            4
                        );
                    }
                }
            }
        }

        if ($this->notSynced) {
            $this->output('Templates not updated:');
            foreach ($this->notSynced as $templateKey => $bool) {
                $this->output($templateKey, 'red', 2);
            }
        }

        if ($this->recommendWildcards) {
            $this->output('Recommend to use wildcard template:');
            foreach ($this->recommendWildcards as $templateKey => $paths) {
                $this->output($templateKey . ' => ' . $templateKey . '*', null, 2);

                foreach ($paths as $line) {
                    $this->output(
                        'Used in: ' . $this->relativePath($line[0]) . '::' . $line[1],
                        'green',
                        4
                    );
                }
            }
        }
    }

    protected function getDevTemplateContent($type, $templateId)
    {
        if (strpos($templateId, '.') === false) {
            $templateId .= '.html';
        }

        $path = sprintf('%s/_output/templates/%s/%s', $this->addOnDir, $type, $templateId);

        return file_get_contents($path);
    }

    protected function collectUsedTemplates()
    {
        // template="template_name"
        // admin:template_name
        // public:template_name
        $pattern = '/(template|admin|public)(="|:|\')([a-z0-9_\.\*]+)(\'|")?/i';

        // TODO: Pattern to matching templates from Controller
        // ->view('SomeClass', 'SomeTemplate',...)

        foreach ($this->files as $path) {
            if (strpos($path, '/_data/') !== false) {
                // ignore all files in _data folder to prevent
                // used in old template data.
                continue;
            }

            $content = file_get_contents($path);

            preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
            if (empty($matches[3])) {
                continue;
            }
            
            foreach ($matches[3] as $index => $match) {
                $type = $matches[1][$index][0];
                $templateId = $match[0];

                if ($type === 'template') {
                    if (strpos($path, '/_output/templates/admin/') !== false) {
                        $type = 'admin';
                    } elseif (strpos($path, '/_output/templates/public/') !== false) {
                        $type = 'public';
                    } else {
                        throw new \LogicException('Unknown template type. Path: ' . $this->relativePath($path));
                    }
                }

                if (substr($templateId, -1) === '_') {
                    // recommend to using wildcard template.
                    $this->recommendWildcards[$this->getTemplateKey($type, $templateId)][] = [
                        $path,
                        $this->getLine($content, $match[1])
                    ];

                    continue;
                }

                $this->usedTemplates[$this->getTemplateKey($type, $templateId)][] = [
                    $path,
                    $this->getLine($content, $match[1])
                ];
            }
        }
    }

    protected function loadAddOnTemplates()
    {
        /** @var \XF\Finder\Template $finder */
        $finder = \XF::finder('XF:Template');
        $this->templates = $finder->fromAddOn($this->addOnId)->fetch();
    }

    protected function getTemplateKey($type, $templateId)
    {
        return $type . ':' . $templateId;
    }
}