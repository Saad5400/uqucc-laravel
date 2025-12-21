<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Objects\Message;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class PythonExecutionHandler extends BaseHandler
{
    public function handle(Message $message): void
    {
        $content = trim($message->getText() ?? '');
        $userId = $message->getFrom()->getId();

        // Check if user is providing input for previous Python code
        $userState = $this->getUserState($userId);
        if ($userState && $userState['waiting_for_input']) {
            $this->executeWithInput($message, $userId);
            return;
        }

        // Check for new Python execution command
        if (preg_match('/^شغل بايثون\s+(.+)$/us', $content, $matches)) {
            $code = trim($matches[1]);
            $this->executePython($message, $code, $userId);
        }
    }

    protected function executePython(Message $message, string $code, int $userId): void
    {
        // Check if code uses input()
        if (str_contains($code, 'input(')) {
            $this->setUserState($userId, [
                'waiting_for_input' => true,
                'code' => $code,
            ]);
            $this->reply($message, 'الكود الخاص بك يستخدم input(). يرجى إرسال المدخل الذي تريد توفيره.');
            return;
        }

        $this->runPythonCode($message, $code);
    }

    protected function executeWithInput(Message $message, int $userId): void
    {
        $input = $message->getText();
        $userState = $this->getUserState($userId);
        $code = $userState['code'];

        $this->clearUserState($userId);

        $this->runPythonCode($message, $code, $input);
    }

    protected function runPythonCode(Message $message, string $code, ?string $input = null): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'py_') . '.py';
        file_put_contents($tempFile, $code);

        $process = new Process(['python3', $tempFile]);
        $process->setTimeout(5);

        if ($input !== null) {
            $process->setInput($input . "\n");
        }

        try {
            $process->run();

            if (!$process->isSuccessful()) {
                $error = $process->getErrorOutput() ?: 'لم يتم التقاط رسالة خطأ.';
                $this->reply($message, "خطأ وقت التشغيل:\n```\n{$error}\n```", 'Markdown');
            } else {
                $output = $process->getOutput();
                if (!empty(trim($output))) {
                    $this->reply($message, "```\n" . trim($output) . "\n```", 'Markdown');
                } else {
                    $this->reply($message, 'لا يوجد مخرجات.');
                }
            }
        } catch (ProcessTimedOutException $e) {
            $this->reply($message, 'خطأ: انتهت مهلة التنفيذ.');
        } finally {
            @unlink($tempFile);
        }
    }
}
