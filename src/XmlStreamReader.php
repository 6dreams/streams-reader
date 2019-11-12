<?php
declare(strict_types = 1);

namespace SixDreams\StreamReader;

/**
 * XML Stream reader.
 */
class XmlStreamReader implements StreamReaderInterface
{
    /** @var resource */
    private $parser;

    /** @var callable */
    private $optionsCallback;

    /** @var bool */
    private $lowerCase;

    /** @var string */
    protected $extractPath;

    /** @var string */
    protected $collectPath;

    /** @var callable */
    protected $callback;

    /** @var string */
    protected $currentPath;

    /** @var bool */
    protected $extracting;

    /** @var bool */
    protected $collecting;

    /** @var array */
    protected $collected;

    /** @var int */
    protected $collectedRef;

    /**
     * @inheritdoc
     */
    public function parse($data, int $buffer = 1024): bool
    {
        $this->parser = \xml_parser_create();
        \xml_set_object($this->parser, $this);

        \xml_parser_set_option($this->parser, \XML_OPTION_CASE_FOLDING, false);
        \xml_parser_set_option($this->parser, \XML_OPTION_SKIP_WHITE, true);
        if ($this->optionsCallback) {
            ($this->optionsCallback)($this->parser);
        }
        \xml_set_element_handler($this->parser, 'parseStart', 'parseEnd');
        \xml_set_character_data_handler($this->parser, 'parseData');

        // Rewind to start.
        if ((\stream_get_meta_data($data)['seekable'] ?? false) === true) {
            \fseek($data, 0);
        }

        $this->currentPath  = '';
        $this->collectedRef = 0;

        $chunk = \fread($data, $buffer);
        while ($chunk !== false) {
            $eof = \feof($data);
            if (\xml_parse($this->parser, $chunk, $eof) !== 1) {
                $code = \xml_get_error_code($this->parser);
                $line = \xml_get_current_line_number($this->parser);
                $this->finish();

                throw new \Exception(\sprintf('XML Parse Error: %d at line %d (chunk: %s)', $code, $line, $chunk));
            }
            if ($eof) {
                break;
            }
            $chunk = \fread($data, $buffer);
        }
        $this->finish();

        return false;
    }

    /**
     * @inheritdoc
     */
    public function registerCallback(
        ?string $collectPath,
        string $extractPath,
        callable $callback
    ): StreamReaderInterface {
        $this->callback    = $callback;
        $this->extractPath = $extractPath;
        $this->collectPath = $collectPath;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setOptionCallbacks(callable $optionsCallback): StreamReaderInterface
    {
        $this->optionsCallback = $optionsCallback;

        return $this;
    }

    /**
     * Force close and reset all internal props.
     */
    private function finish(): void
    {
        if (\is_resource($this->parser)) {
            \xml_parser_free($this->parser);
        }
        $this->extracting = false;
        $this->collecting = false;
        $this->collected  = [];
    }

    /**
     * XML Callback: start xml node.
     *
     * @param resource $parser
     * @param string   $name
     * @param array    $attributes
     */
    private function parseStart($parser, string $name, array $attributes): void
    {
        $this->currentPath = $this->currentPath . '/' . \strtolower($name);
        $this->checkPath();

        if ($this->collecting || $this->extracting) {
            if ($this->extracting && !$this->isExtract()) {
                $this->addData($this->buildElement([$name, $attributes, '']));
                return;
            }
            $this->addElement([$name, $attributes, '']);
        }
    }

    /**
     * XML Callback: finish xml node.
     *
     * @param resource $parser
     * @param string   $name
     */
    private function parseEnd($parser, string $name): void
    {
        $extract = $this->isExtract();
        if ($extract) {
            $xml = '';
            foreach ($this->collected as $value) {
                $xml .= $this->buildElement($value);
            }
            foreach (\array_reverse($this->collected) as $value) {
                $xml .= $this->closeElement($value[0]);
            }
            ($this->callback)($xml);
        }
        if ($this->collecting || $this->extracting) {
            if ($this->extracting && !$extract) {
                $this->addData($this->closeElement($name));
            } else {
                $this->collectedRef = $this->collectedRef - 1;
                unset($this->collected[$this->collectedRef]);
            }
        }

        $this->currentPath = \substr(
            $this->currentPath,
            0,
            \strlen($this->currentPath) - (\strlen($name) + 1)
        );

        $this->checkPath();
    }

    /**
     * XML Callback: content of xml node.
     *
     * @param resource $parser
     * @param string   $data
     */
    private function parseData($parser, string $data): void
    {
        if (\strlen(\trim($data)) === 0) {
            return;
        }
        if (!$this->collecting && !$this->extracting) {
            return;
        }
        $this->addData('<![CDATA[' . $data . ']]>');
    }

    /**
     * Append content to current node.
     *
     * @param string $data
     */
    private function addData(string $data): void
    {
        $ref = $this->collectedRef - 1;
        $this->collected[$ref][2] = $this->collected[$ref][2] . $data;
    }

    /**
     * Add new element to collected node.
     *
     * @param array $element
     */
    private function addElement(array $element): void
    {
        $this->collected[$this->collectedRef] = $element;
        $this->collectedRef++;
    }

    /**
     * Builds XML open element.
     *
     * @param array element
     *
     * @return string
     */
    private function buildElement(array $element): string
    {
        $ret = '<' . ($this->lowerCase ? \strtolower($element[0]) : $element[0]);
        foreach ($element[1] as $k => $v) {
            $ret = $ret . ' ' . ($this->lowerCase ? \strtolower($k) : $k) . '="' . \htmlentities($v, ENT_QUOTES, 'UTF-8') . '"';
        }

        return $ret . '>' . $element[2];
    }

    /**
     * Change internal state of parsing.
     */
    private function checkPath(): void
    {
        if ($this->collecting !== null) {
            $this->collecting = \strpos($this->currentPath, $this->collectPath) === 0;
        }

        $this->extracting = \strpos($this->currentPath, $this->extractPath) === 0;
    }

    /**
     * Create closing XML node.
     *
     * @param string $name
     *
     * @return string
     */
    private function closeElement(string $name): string
    {
        return "</" . ($this->lowerCase ? \strtolower($name) : $name) . ">";
    }

    /**
     * Is current path equals extract.
     *
     * @return bool
     */
    private function isExtract(): bool
    {
        return $this->currentPath === $this->extractPath;
    }
}
