<?php

namespace Drupal\Tests\feature_page_redirects\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\Node;
use Drupal\redirect\Entity\Redirect;

/**
 * Tests for the Feature Page Redirects module.
 *
 * @group feature_page_redirects
 */
class FeaturePageRedirectsTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'node',
    'field',
    'text',
    'user',
    'system',
    'redirect',
    'moody_feature_page',
    'feature_page_redirects',
  ];

  /**
   * The default theme to install.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with permission to create and edit content.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $contentUser;

  /**
   * A user with permission to administer redirects.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    
    // Create users with appropriate permissions.
    $this->contentUser = $this->drupalCreateUser([
      'create moody_feature_page content',
      'edit own moody_feature_page content',
      'view own unpublished content',
    ]);
    
    $this->adminUser = $this->drupalCreateUser([
      'create moody_feature_page content',
      'edit any moody_feature_page content',
      'administer redirects',
      'access administration pages',
    ]);
  }

  /**
   * Tests that a redirect is honored when viewing a moody_feature_page node.
   */
  public function testRedirectOnNodeView() {
    $this->drupalLogin($this->adminUser);
    
    // Create a moody_feature_page node.
    $node = Node::create([
      'type' => 'moody_feature_page',
      'title' => 'Test Feature Page',
      'status' => 1,
    ]);
    $node->save();
    
    $node_path = '/node/' . $node->id();
    
    // Create a redirect for this node.
    $redirect = Redirect::create([
      'redirect_source' => ltrim($node_path, '/'),
      'redirect_redirect' => 'internal:/',
      'status_code' => 301,
      'language' => 'und',
    ]);
    $redirect->save();
    
    // Visit the node page and verify the redirect happens.
    $this->drupalGet($node_path);
    // After redirect, we should be on the front page.
    $this->assertSession()->addressEquals('/');
  }

  /**
   * Tests that edit pages are not redirected.
   */
  public function testEditPageNotRedirected() {
    $this->drupalLogin($this->adminUser);
    
    // Create a moody_feature_page node.
    $node = Node::create([
      'type' => 'moody_feature_page',
      'title' => 'Test Feature Page for Edit',
      'status' => 1,
    ]);
    $node->save();
    
    $node_path = '/node/' . $node->id();
    
    // Create a redirect for this node.
    $redirect = Redirect::create([
      'redirect_source' => ltrim($node_path, '/'),
      'redirect_redirect' => 'internal:/',
      'status_code' => 301,
      'language' => 'und',
    ]);
    $redirect->save();
    
    // Visit the edit page and verify it loads normally.
    $this->drupalGet($node_path . '/edit');
    // We should still be on the edit page, not redirected.
    $this->assertSession()->addressEquals($node_path . '/edit');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that nodes without redirects are not affected.
   */
  public function testNoRedirectWithoutMatchingRedirect() {
    $this->drupalLogin($this->contentUser);
    
    // Create a moody_feature_page node without a redirect.
    $node = Node::create([
      'type' => 'moody_feature_page',
      'title' => 'Test Feature Page No Redirect',
      'status' => 1,
      'uid' => $this->contentUser->id(),
    ]);
    $node->save();
    
    $node_path = '/node/' . $node->id();
    
    // Visit the node page and verify normal viewing works.
    $this->drupalGet($node_path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals($node_path);
    $this->assertSession()->pageTextContains('Test Feature Page No Redirect');
  }

  /**
   * Tests that removed redirects allow normal node viewing.
   */
  public function testRemovedRedirectAllowsViewing() {
    $this->drupalLogin($this->adminUser);
    
    // Create a moody_feature_page node.
    $node = Node::create([
      'type' => 'moody_feature_page',
      'title' => 'Test Feature Page Removed Redirect',
      'status' => 1,
    ]);
    $node->save();
    
    $node_path = '/node/' . $node->id();
    
    // Create a redirect for this node.
    $redirect = Redirect::create([
      'redirect_source' => ltrim($node_path, '/'),
      'redirect_redirect' => 'internal:/',
      'status_code' => 301,
      'language' => 'und',
    ]);
    $redirect->save();
    
    // Delete the redirect.
    $redirect->delete();
    
    // Visit the node page and verify normal viewing works.
    $this->drupalGet($node_path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals($node_path);
    $this->assertSession()->pageTextContains('Test Feature Page Removed Redirect');
  }

}
