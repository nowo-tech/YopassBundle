<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Creator;

/**
 * Port: turns the user handed to the bundle (the security-token user, a list / delete-all subject) into the object
 * stored and queried as the share creator, and gives its scalar id.
 *
 * The default ({@see DoctrineCreatorReferenceResolver}) returns instances of `nowo_yopass.user_class` unchanged and
 * turns any other user exposing `getId()` (a security user that is no Doctrine entity, a DTO, a read model) into a
 * Doctrine reference of `user_class` with that id, so the token user does not have to be the mapped entity. With
 * `nowo_yopass.user_mapping: id` (1.18.0) it returns a {@see CreatorId} instead. Alias the interface to your own
 * service when the ids differ.
 */
interface CreatorReferenceResolverInterface
{
    /**
     * The creator object to store / query for the given user (the user itself when it already is one).
     */
    public function reference(object $user): object;

    /**
     * Scalar id of a creator or user (`getId()` as a string), or null when it exposes none.
     */
    public function idOf(object $user): ?string;
}
