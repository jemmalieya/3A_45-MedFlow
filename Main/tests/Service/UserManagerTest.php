<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\UserManager;
use PHPUnit\Framework\TestCase;

class UserManagerTest extends TestCase
{
    private function makeValidUser(): User
    {
        $user = new User();
        $user->setPrenom('Wael');
        $user->setEmailUser('wael@test.com');
        $user->setPlainPassword('Password@123');

        // ⚠️ Remplace setDateNaissance() si ton setter a un autre nom
        $user->setDateNaissance(new \DateTimeImmutable('2000-01-01')); // >=18

        return $user;
    }

    public function testValidUser()
    {
        $user = $this->makeValidUser();
        $manager = new UserManager();

        $this->assertTrue($manager->validate($user));
    }

    public function testUserWithoutPrenom()
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = $this->makeValidUser();
        $user->setPrenom('');

        (new UserManager())->validate($user);
    }

    public function testUserWithInvalidEmail()
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = $this->makeValidUser();
        $user->setEmailUser('email_invalide');

        (new UserManager())->validate($user);
    }

    public function testUserWithWeakPassword()
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = $this->makeValidUser();
        $user->setPlainPassword('12345678'); // pas majuscule/special

        (new UserManager())->validate($user);
    }

    public function testUserBirthDateCannotBeNullBecauseOfTypeHint()
{
    $this->expectException(\TypeError::class);

    $user = $this->makeValidUser();
    $user->setDateNaissance(null); // interdit par signature => TypeError
}
    public function testUserWithFutureBirthDate()
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = $this->makeValidUser();
        $user->setDateNaissance(new \DateTimeImmutable('tomorrow'));

        (new UserManager())->validate($user);
    }

    public function testUserTooYoung()
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = $this->makeValidUser();
        $user->setDateNaissance(new \DateTimeImmutable('2012-01-01')); // <18

        (new UserManager())->validate($user);
    }
}