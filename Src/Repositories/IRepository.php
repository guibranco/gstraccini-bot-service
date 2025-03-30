<?php 

namespace GuiBranco\GStracciniBot\Repositories;

interface IRepository
{
    public function getAll(): array;

    public function getById($id): ?object;

    public function upsert($entity): int;

    public function delete($id): void;
}

