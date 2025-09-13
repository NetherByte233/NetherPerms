<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\storage;

interface StorageInterface
{
    public function reload() : void;
    public function save() : void;

    /** @return array<string,mixed> */
    public function getUsers() : array;
    /** @param array<string,mixed> $users */
    public function setUsers(array $users) : void;

    /** @return array<string,mixed> */
    public function getGroups() : array;
    /** @param array<string,mixed> $groups */
    public function setGroups(array $groups) : void;

    /** @return array<string,mixed> */
    public function getTracks() : array;
    /** @param array<string,mixed> $tracks */
    public function setTracks(array $tracks) : void;
}
