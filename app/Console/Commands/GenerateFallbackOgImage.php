<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateFallbackOgImage extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'og:generate-fallback';

    /**
     * The console command description.
     */
    protected $description = 'Generate a fallback OG image for when screenshot generation fails';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $width = 1200;
        $height = 630;
        $outputPath = public_path('images/og-fallback.png');

        // Check if GD extension is available
        if (! extension_loaded('gd')) {
            $this->error('GD extension is not loaded. Cannot generate fallback image.');

            return self::FAILURE;
        }

        // Create image
        $image = imagecreatetruecolor($width, $height);

        if (! $image) {
            $this->error('Failed to create image.');

            return self::FAILURE;
        }

        // Define colors
        $bgColor = imagecolorallocate($image, 15, 23, 42); // Slate-900
        $textColor = imagecolorallocate($image, 255, 255, 255); // White
        $accentColor = imagecolorallocate($image, 59, 130, 246); // Blue-500

        // Fill background
        imagefilledrectangle($image, 0, 0, $width, $height, $bgColor);

        // Add a gradient effect (simple version)
        $gradientHeight = 200;
        for ($i = 0; $i < $gradientHeight; $i++) {
            $alpha = (int) (127 * (1 - $i / $gradientHeight));
            $gradientColor = imagecolorallocatealpha($image, 59, 130, 246, $alpha);
            imagefilledrectangle($image, 0, $height - $gradientHeight + $i, $width, $height - $gradientHeight + $i + 1, $gradientColor);
        }

        // Get site name
        $siteName = config('app.name', 'Laravel');
        $description = 'دليل طالب كلية الحاسبات';

        // Use default font (built-in)
        $fontSize = 5; // 1-5 for built-in fonts

        // Calculate text positions (centered)
        $titleWidth = imagefontwidth($fontSize) * mb_strlen($siteName);
        $titleX = (int) (($width - $titleWidth) / 2);
        $titleY = (int) ($height / 2 - 40);

        $descWidth = imagefontwidth($fontSize) * mb_strlen($description);
        $descX = (int) (($width - $descWidth) / 2);
        $descY = (int) ($height / 2 + 20);

        // Draw text (using built-in fonts which don't support Arabic well, but it's a fallback)
        imagestring($image, $fontSize, $titleX, $titleY, $siteName, $textColor);
        imagestring($image, $fontSize, $descX, $descY, $description, $textColor);

        // Save image
        $success = imagepng($image, $outputPath);
        imagedestroy($image);

        if ($success) {
            $this->info("Fallback OG image generated successfully at: {$outputPath}");

            return self::SUCCESS;
        }

        $this->error('Failed to save fallback image.');

        return self::FAILURE;
    }
}
