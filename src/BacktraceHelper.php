<?php

declare(strict_types=1);

namespace Cardyo\SpiralSentryTracing;

use Sentry\Frame;
use Sentry\FrameBuilder;
use Sentry\Options;
use Sentry\Serializer\RepresentationSerializerInterface;
use Spiral\Core\Attribute\Singleton;

/**
 * @internal
 */
class BacktraceHelper
{
    /**
     * @var Options The SDK client options
     */
    private Options $options;

    /**
     * @var FrameBuilder An instance of the builder of {@see Frame} objects
     */
    private FrameBuilder $frameBuilder;

    /**
     * Constructor.
     *
     * @param Options $options The SDK client options
     * @param RepresentationSerializerInterface $representationSerializer The representation serializer
     */
    public function __construct(Options $options, RepresentationSerializerInterface $representationSerializer)
    {
        $this->options = $options;
        $this->frameBuilder = new FrameBuilder($options, $representationSerializer);
    }

    /**
     * Find the first in app frame for a given backtrace.
     *
     * @param array<int, array<string, mixed>> $backtrace The backtrace
     * @phpstan-param list<array{
     *     line?: int,
     *     file?: string,
     * }> $backtrace
     */
    public function findFirstInAppFrameForBacktrace(array $backtrace): ?Frame
    {
        $file = Frame::INTERNAL_FRAME_FILENAME;
        $line = 0;

        foreach ($backtrace as $backtraceFrame) {
            $frame = $this->frameBuilder->buildFromBacktraceFrame($file, $line, $backtraceFrame);

            if ($frame->isInApp()) {
                return $frame;
            }

            $file = $backtraceFrame['file'] ?? Frame::INTERNAL_FRAME_FILENAME;
            $line = $backtraceFrame['line'] ?? 0;
        }

        return null;
    }

    /**
     * Takes a frame and if it's a compiled view path returns the original view path.
     */
    public function getOriginalViewPathForFrameOfCompiledViewPath(Frame $frame): ?string
    {
        // If for some reason the file does not exist, skip resolving
        if (!file_exists($frame->getAbsoluteFilePath())) {
            return null;
        }

        $viewFileContents = file_get_contents($frame->getAbsoluteFilePath());

        preg_match('/PATH (?<originalPath>.*?) ENDPATH/', $viewFileContents, $matches);

        // No path comment found in the file, must be a very old Laravel version
        if (empty($matches['originalPath'])) {
            return null;
        }

        return $this->stripPrefixFromFilePath($matches['originalPath']);
    }

    /**
     * Removes from the given file path the specified prefixes.
     *
     * @param string $filePath The path to the file
     */
    private function stripPrefixFromFilePath(string $filePath): string
    {
        foreach ($this->options->getPrefixes() as $prefix) {
            if (str_starts_with($filePath, $prefix)) {
                return mb_substr($filePath, mb_strlen($prefix));
            }
        }

        return $filePath;
    }
}
