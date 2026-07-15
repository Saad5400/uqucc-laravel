<?php

namespace App\Services\Logic;

use GdImage;
use RuntimeException;

/**
 * Renders a {@see TruthTable} to a PNG using GD and the bundled DejaVu Sans
 * Mono font — no browser or external binary involved, so it takes
 * milliseconds. Built for the Telegram bot, where a `<pre>` text table wraps
 * and breaks on mobile; any other surface that wants a picture can reuse it.
 */
class TruthTableImageRenderer
{
    private const FONT_SIZE = 15;

    private const TITLE_FONT_SIZE = 17;

    private const CELL_PADDING_X = 16;

    private const CELL_PADDING_Y = 11;

    private const MARGIN = 22;

    /** Rendered at 2x then advertised as-is: crisp on mobile without huge files. */
    private const SCALE = 2;

    /**
     * Render the table (title line with the formula, then the grid) to PNG
     * bytes.
     *
     * @throws RuntimeException when GD cannot allocate the image
     */
    public function render(TruthTable $table): string
    {
        $scale = self::SCALE;
        $fontSize = self::FONT_SIZE * $scale;
        $titleFontSize = self::TITLE_FONT_SIZE * $scale;
        $paddingX = self::CELL_PADDING_X * $scale;
        $paddingY = self::CELL_PADDING_Y * $scale;
        $margin = self::MARGIN * $scale;

        $columnWidths = array_map(
            fn (string $label): int => $this->textWidth($label, $fontSize, $this->boldFont()) + 2 * $paddingX,
            $table->columns,
        );

        $rowHeight = $this->lineHeight($fontSize) + 2 * $paddingY;
        $titleHeight = $this->lineHeight($titleFontSize) + $paddingY;

        $tableWidth = array_sum($columnWidths);
        $width = $tableWidth + 2 * $margin;
        $height = $titleHeight + (count($table->rows) + 1) * $rowHeight + 2 * $margin;

        $image = imagecreatetruecolor($width, $height);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('Could not allocate the truth table image.');
        }

        $background = imagecolorallocate($image, 255, 255, 255);
        $text = imagecolorallocate($image, 31, 41, 55);
        $muted = imagecolorallocate($image, 107, 114, 128);
        $grid = imagecolorallocate($image, 209, 213, 219);
        $headerBackground = imagecolorallocate($image, 243, 244, 246);
        $resultBackground = imagecolorallocate($image, 238, 242, 255);
        $trueColor = imagecolorallocate($image, 22, 163, 74);
        $falseColor = imagecolorallocate($image, 220, 38, 38);

        imagefill($image, 0, 0, $background);

        $this->drawText($image, $table->formula, $margin, $margin, $titleFontSize, $muted, $this->boldFont());

        $top = $margin + $titleHeight;
        $lastColumn = count($table->columns) - 1;

        $columnOffsets = [];
        $x = $margin;

        foreach ($columnWidths as $index => $columnWidth) {
            $columnOffsets[$index] = $x;
            $x += $columnWidth;
        }

        imagefilledrectangle($image, $margin, $top, $margin + $tableWidth - 1, $top + $rowHeight - 1, $headerBackground);
        imagefilledrectangle(
            $image,
            $columnOffsets[$lastColumn],
            $top + $rowHeight,
            $columnOffsets[$lastColumn] + $columnWidths[$lastColumn] - 1,
            $top + (count($table->rows) + 1) * $rowHeight - 1,
            $resultBackground,
        );

        foreach ($table->columns as $index => $label) {
            $this->drawCentered($image, $label, $columnOffsets[$index], $columnWidths[$index], $top + $paddingY, $fontSize, $text, $this->boldFont());
        }

        foreach ($table->rows as $rowIndex => $row) {
            $y = $top + ($rowIndex + 1) * $rowHeight + $paddingY;

            foreach ($row as $columnIndex => $value) {
                $this->drawCentered(
                    $image,
                    $value ? 'T' : 'F',
                    $columnOffsets[$columnIndex],
                    $columnWidths[$columnIndex],
                    $y,
                    $fontSize,
                    $value ? $trueColor : $falseColor,
                    $columnIndex === $lastColumn ? $this->boldFont() : $this->regularFont(),
                );
            }
        }

        $bottom = $top + (count($table->rows) + 1) * $rowHeight;

        for ($rowIndex = 0; $rowIndex <= count($table->rows) + 1; $rowIndex++) {
            $y = $top + $rowIndex * $rowHeight;
            imageline($image, $margin, $y, $margin + $tableWidth, $y, $grid);
        }

        foreach ($columnOffsets as $offset) {
            imageline($image, $offset, $top, $offset, $bottom, $grid);
        }

        imageline($image, $margin + $tableWidth, $top, $margin + $tableWidth, $bottom, $grid);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function drawCentered(GdImage $image, string $value, int $columnOffset, int $columnWidth, int $y, int $fontSize, int $color, string $font): void
    {
        $x = $columnOffset + intdiv($columnWidth - $this->textWidth($value, $fontSize, $font), 2);

        $this->drawText($image, $value, $x, $y, $fontSize, $color, $font);
    }

    /**
     * Draw text with (x, y) as the TOP-left corner — imagettftext expects the
     * baseline, which is what lineHeight()/ascent() compensate for.
     */
    private function drawText(GdImage $image, string $value, int $x, int $y, int $fontSize, int $color, string $font): void
    {
        imagettftext($image, $fontSize, 0, $x, $y + $this->ascent($fontSize), $color, $font, $value);
    }

    private function textWidth(string $value, int $fontSize, string $font): int
    {
        $box = imagettfbbox($fontSize, 0, $font, $value);

        return abs($box[4] - $box[0]);
    }

    private function lineHeight(int $fontSize): int
    {
        return (int) ceil($fontSize * 1.4);
    }

    private function ascent(int $fontSize): int
    {
        return (int) ceil($fontSize * 1.05);
    }

    private function regularFont(): string
    {
        return resource_path('fonts/DejaVuSansMono.ttf');
    }

    private function boldFont(): string
    {
        return resource_path('fonts/DejaVuSansMono-Bold.ttf');
    }
}
