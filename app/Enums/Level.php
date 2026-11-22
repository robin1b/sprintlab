<?php

namespace App\Enums;

enum Level: string
{
    case Junior = 'junior';
    case Medior = 'medior';
    case Senior = 'senior';

    public function label(): string
    {
        return match ($this) {
            self::Junior => 'Junior',
            self::Medior => 'Medior',
            self::Senior => 'Senior',
        };
    }

    /**
     * The level after this one, or null at the top.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Junior => self::Medior,
            self::Medior => self::Senior,
            self::Senior => null,
        };
    }
}
