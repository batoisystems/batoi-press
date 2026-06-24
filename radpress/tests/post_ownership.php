<?php
declare(strict_types=1);

use Batoi\Press\Security\AdminAccess;

require dirname(__DIR__) . '/autoload.php';

$owner = ['username' => 'owner', 'role' => 'owner'];
$admin = ['username' => 'admin', 'role' => 'admin'];
$editor = ['username' => 'editor', 'role' => 'editor'];
$author = ['username' => 'alice', 'role' => 'author'];
$otherAuthor = ['username' => 'bob', 'role' => 'author'];
$viewer = ['username' => 'viewer', 'role' => 'viewer'];

$alicePost = ['slug' => 'alice-post', 'author' => 'alice'];
$bobPost = ['slug' => 'bob-post', 'author' => 'bob'];
$unassignedPost = ['slug' => 'unassigned-post'];

assertTrue(AdminAccess::canManagePost($owner, $bobPost), 'owners should manage any post');
assertTrue(AdminAccess::canManagePost($admin, $bobPost), 'admins should manage any post');
assertTrue(AdminAccess::canManagePost($editor, $bobPost), 'editors should manage any post');
assertTrue(AdminAccess::canManagePost($author, $alicePost), 'authors should manage their own posts');
assertTrue(!AdminAccess::canManagePost($author, $bobPost), 'authors should not manage other authors posts');
assertTrue(!AdminAccess::canManagePost($author, $unassignedPost), 'authors should not manage unassigned posts');
assertTrue(!AdminAccess::canManagePost($viewer, $alicePost), 'viewers should not manage posts');

$filtered = AdminAccess::filterManageablePosts($author, [$alicePost, $bobPost, $unassignedPost]);
assertSame(['alice-post'], array_map(static fn (array $post): string => (string)$post['slug'], $filtered), 'author post lists should include only own posts');

$editorFiltered = AdminAccess::filterManageablePosts($editor, [$alicePost, $bobPost]);
assertSame(['alice-post', 'bob-post'], array_map(static fn (array $post): string => (string)$post['slug'], $editorFiltered), 'editors should see all posts');

$otherFiltered = AdminAccess::filterManageablePosts($otherAuthor, [$alicePost, $bobPost]);
assertSame(['bob-post'], array_map(static fn (array $post): string => (string)$post['slug'], $otherFiltered), 'each author should see only their posts');

echo "Post ownership checks passed\n";

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected ' . json_encode($expected) . ' got ' . json_encode($actual));
    }
}
