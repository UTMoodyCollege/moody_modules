<?php

// Run with php; tiny role double tests the real update without changing a site.
namespace Drupal\user\Entity {
  final class Role {
    public static ?self $role = NULL;
    public array $permissions = ['access content'];
    public int $saves = 0;
    public static function load($id): ?self {
      if ($id !== 'standard_content_manager') {
        throw new \RuntimeException('Wrong role selected.');
      }
      return self::$role;
    }
    public function hasPermission($permission): bool {
      return in_array($permission, $this->permissions, TRUE);
    }
    public function grantPermission($permission): self {
      $this->permissions[] = $permission;
      return $this;
    }
    public function save(): void {
      $this->saves++;
    }
  }
}

namespace {
  require __DIR__ . '/../moody_page_launch.install';
  use Drupal\user\Entity\Role;
  if (!str_contains(moody_page_launch_update_10001(), 'Skipped') || Role::$role !== NULL) {
    throw new RuntimeException('Missing role must be skipped.');
  }
  Role::$role = new Role();
  moody_page_launch_update_10001();
  moody_page_launch_update_10001();
  if (Role::$role->permissions !== ['access content', 'administer moody page launches'] || Role::$role->saves !== 1) {
    throw new RuntimeException('Grant must preserve other permissions and be idempotent.');
  }
  echo "Role grant, preservation, idempotence, and missing-role skip passed.\n";
}
