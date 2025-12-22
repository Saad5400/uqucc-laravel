<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Objects\Message;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class JavaExecutionHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        $text = $message->getText();
        // Ensure getText() returns a string (handle edge cases where it might be an array)
        $content = is_string($text) ? trim($text) : '';
        $userId = $message->getFrom()->getId();

        // Check if user is providing input for previous Java code
        $userState = $this->getUserState($userId);
        if ($userState && $userState['waiting_for_input']) {
            $this->executeWithInput($message, $userId);
            return;
        }

        // Check for new Java execution command
        if (preg_match('/^شغل جافا\s+(.+)$/us', $content, $matches)) {
            $code = trim($matches[1]);
            $this->executeJava($message, $code, $userId);
        }
    }

    protected function executeJava(Message $message, string $code, int $userId): void
    {
        // Wrap code if needed
        $wrappedCode = $this->wrapJavaCode($code);
        $className = $this->extractClassName($wrappedCode);

        // Check if code uses Scanner
        if (str_contains($wrappedCode, 'new Scanner(System.in)')) {
            $this->setUserState($userId, [
                'waiting_for_input' => true,
                'code' => $wrappedCode,
                'className' => $className,
            ]);
            $this->reply($message, 'الكود الخاص بك يستخدم Scanner. يرجى إرسال المدخل (في رسالة واحدة) الذي تريد توفيره.');
            return;
        }

        $this->runJavaCode($message, $wrappedCode, $className);
    }

    protected function executeWithInput(Message $message, int $userId): void
    {
        $text = $message->getText();
        // Ensure getText() returns a string (handle edge cases where it might be an array)
        $input = is_string($text) ? $text : '';
        $userState = $this->getUserState($userId);
        $code = $userState['code'];
        $className = $userState['className'];

        $this->clearUserState($userId);

        $this->runJavaCode($message, $code, $className, $input);
    }

    protected function wrapJavaCode(string $code): string
    {
        // Check if code already has a class declaration
        if (preg_match('/\bclass\s+\w+/u', $code)) {
            return $code;
        }

        // Add imports and wrap in class
        $imports = "import java.util.*;\nimport java.io.*;\nimport java.time.*;";

        if (preg_match('/public\s+static\s+void\s+main/u', $code)) {
            return "$imports\n\npublic class TempJavaProgram {\n$code\n}";
        }

        // Wrap free code in main method
        $indentedCode = implode("\n", array_map(fn ($line) => "        $line", explode("\n", $code)));

        return "$imports\n\npublic class TempJavaProgram {\n    public static void main(String[] args) {\n$indentedCode\n    }\n}";
    }

    protected function extractClassName(string $code): string
    {
        if (preg_match('/\bclass\s+(\w+)/u', $code, $matches)) {
            return $matches[1];
        }

        return 'TempJavaProgram';
    }

    protected function runJavaCode(Message $message, string $code, string $className, ?string $input = null): void
    {
        $tempDir = sys_get_temp_dir() . '/java_' . uniqid();
        mkdir($tempDir);

        $javaFile = "$tempDir/$className.java";
        file_put_contents($javaFile, $code);

        // Compile
        $compileProcess = new Process(['javac', $javaFile]);
        $compileProcess->setTimeout(10);

        try {
            $compileProcess->run();

            if (!$compileProcess->isSuccessful()) {
                $error = $compileProcess->getErrorOutput();
                $this->reply($message, "خطأ في الترجمة:\n```\n$error\n```", 'Markdown');
                $this->cleanup($tempDir);
                return;
            }

            // Run
            $runProcess = new Process(['java', '-Dfile.encoding=UTF-8', $className], $tempDir);
            $runProcess->setTimeout(2);

            if ($input !== null) {
                // Add extra newlines to handle multiple inputs
                $runProcess->setInput($input . str_repeat("\n", 1000));
            }

            $runProcess->run();

            if (!$runProcess->isSuccessful()) {
                $error = $runProcess->getErrorOutput() ?: 'لا توجد رسالة خطأ.';
                $this->reply($message, "خطأ وقت التشغيل:\n```\n$error\n```", 'Markdown');
            } else {
                $output = $runProcess->getOutput();
                if (!empty(trim($output))) {
                    $this->reply($message, "```\n" . trim($output) . "\n```", 'Markdown');
                } else {
                    $this->reply($message, 'لا يوجد مخرجات.');
                }
            }
        } catch (ProcessTimedOutException $e) {
            $this->reply($message, 'انتهت مهلة التنفيذ.');
        } finally {
            $this->cleanup($tempDir);
        }
    }

    protected function cleanup(string $dir): void
    {
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }
}
