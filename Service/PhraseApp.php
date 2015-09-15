<?php
/**
 * @author  nediam
 * @date    06.09.2015 22:47
 */

namespace nediam\PhraseAppBundle\Service;


use nediam\PhraseApp\PhraseAppClient;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Translation\TranslationLoader;
use Symfony\Component\Translation\Catalogue\DiffOperation;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Writer\TranslationWriter;

class PhraseApp
{
    /**
     * @var PhraseAppClient
     */
    private $client;
    /**
     * @var TranslationLoader
     */
    private $translationLoader;
    /**
     * @var TranslationWriter
     */
    private $translationWriter;
    /**
     * @var string
     */
    private $projectId;
    /**
     * @var array
     */
    private $locales;
    /**
     * @var string
     */
    private $tmpPath;
    /**
     * @var string
     */
    private $translationsPath;
    /**
     * @var string
     */
    private $outputFormat;
    /**
     * @var array
     */
    private $downloadedLocales = [];

    /**
     * PhraseApp constructor.
     *
     * @param PhraseAppClient   $client
     * @param TranslationLoader $translationLoader
     * @param TranslationWriter $translationWriter
     * @param array             $config
     */
    public function __construct(PhraseAppClient $client, TranslationLoader $translationLoader, TranslationWriter $translationWriter, array $config)
    {
        $this->client            = $client;
        $this->translationLoader = $translationLoader;
        $this->translationWriter = $translationWriter;
        $this->projectId         = $config['project_id'];
        $this->locales           = $config['locales'];
        $this->outputFormat      = $config['output_format'];
        $this->translationsPath  = $config['translations_path'];
    }

    /**
     * Set output format
     *
     * @param string $outputFormat
     *
     * @return PhraseApp
     */
    public function setOutputFormat($outputFormat)
    {
        $this->outputFormat = $outputFormat;

        return $this;
    }

    /**
     * Get Locales
     * @return array
     */
    public function getLocales()
    {
        return $this->locales;
    }

    /**
     * @param $locale
     * @param $format
     *
     * @return array|string
     */
    protected function makeDownloadRequest($locale, $format)
    {
        $response = $this->client->request('locale.download', [
            'project_id'  => $this->projectId,
            'id'          => $locale,
            'file_format' => $format,
        ]);

        return $response['text']->getContents();
    }

    /**
     * @param string $targetLocale
     *
     * @return string
     */
    protected function downloadLocale($targetLocale)
    {
        $sourceLocale = $this->locales[$targetLocale];
        $tmpFile      = $this->getTmpPath() . '/' . 'messages.' . $targetLocale . '.yml';

        if (true === array_key_exists($sourceLocale, $this->downloadedLocales)) {
            // Make copy because operated catalogues must belong to the same locale
            copy($this->downloadedLocales[$sourceLocale], $tmpFile);

            return $this->downloadedLocales[$sourceLocale];
        }

        $phraseAppMessage = $this->makeDownloadRequest($sourceLocale, 'yml_symfony2');
        file_put_contents($tmpFile, $phraseAppMessage);
        $this->downloadedLocales[$sourceLocale] = $tmpFile;

        return $this->downloadedLocales[$sourceLocale];
    }

    /**
     * @param string $targetLocale
     */
    protected function dumpMessages($targetLocale)
    {
        // load downloaded messages
        $extractedCatalogue = new MessageCatalogue($targetLocale);
        $this->translationLoader->loadMessages($this->getTmpPath(), $extractedCatalogue);

        // load any existing messages from the translation files
        $currentCatalogue = new MessageCatalogue($targetLocale);
        $this->translationLoader->loadMessages($this->translationsPath, $currentCatalogue);

        $operation = new DiffOperation($currentCatalogue, $extractedCatalogue);

        // Exit if no messages found.
        if (0 === count($operation->getDomains())) {
            return;
        }

        $this->translationWriter->writeTranslations($operation->getResult(), $this->outputFormat, ['path' => $this->translationsPath]);
    }

    /**
     * @param array $locales
     */
    public function process(array $locales)
    {
        foreach ($locales as $locale)
        {
            $this->downloadLocale($locale);
            $this->dumpMessages($locale);
        }
        //TODO: clean downloaded files
    }

    /**
     * Get TmpPath
     * @return string
     */
    protected function getTmpPath()
    {
        if (null === $this->tmpPath) {
            $this->tmpPath = $this->generateTmpPath();
        }

        return $this->tmpPath;
    }

    /**
     * @return string
     */
    protected function generateTmpPath()
    {
        $tmpPath = sys_get_temp_dir() . '/' . uniqid('translation', false);
        if (!is_dir($tmpPath) && false === @mkdir($tmpPath, 0777, true)) {
            throw new RuntimeException(sprintf('Could not create temporary directory "%s".', $tmpPath));
        }

        return $tmpPath;
    }
}