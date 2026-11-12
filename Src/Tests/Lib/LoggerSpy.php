<?php

namespace GuiBranco\GStracciniBot\Tests\Lib;

/**
 * Stands for the legacy logger, and keeps the messages it was sent.
 */
class LoggerSpy
{

    /**
     * The messages received, in order.
     *
     * @var array
     */
    private array $messages = [];


    /**
     * Takes a message the way the legacy logger does.
     *
     * @param string $message The message.
     *
     * @return void
     */
    public function log(string $message): void
    {
        $this->messages[] = $message;

    }//end log()


    /**
     * The messages received, in order.
     *
     * @return array
     */
    public function messages(): array
    {
        return $this->messages;

    }//end messages()


}//end class
