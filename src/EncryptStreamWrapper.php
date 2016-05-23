<?php

namespace Drupal\file_encrypt;

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\Url;

class EncryptStreamWrapper extends LocalStream {

  const SCHEME = 'encrypt';

  protected $fileInfo;
  protected $mode;
  protected $encryptionProfile;

  /**
   * {@inheritdoc}
   */
  public static function getType() {
    return StreamWrapperInterface::LOCAL_NORMAL;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return t('Encrypted files');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return t('Encrypted local files served by Drupal.');
  }

  /**
   * {@inheritdoc}
   */
  public function getDirectoryPath() {
    return static::basePath();
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    $path = str_replace('\\', '/', $this->getTarget());
    return Url::fromRoute('system.encrypted_file_download', ['filepath' => $path], ['absolute' => TRUE])
      ->toString(TRUE)->getGeneratedUrl();
  }

  /**
   * Returns the base path for encrypted://.
   *
   * Note that this static method is used by \Drupal\system\Form\FileSystemForm
   * so you should alter that form or substitute a different form if you change
   * the class providing the stream_wrapper.private service.
   *
   * @return string
   *   The base path for private://.
   */
  public static function basePath() {
    return Settings::get('encrypted_file_path', '');
  }

  /**
   * @param string $raw_file
   * @param string $encryption_profile
   *
   * @return string
   */
  protected function decrypt($raw_file, $encryption_profile) {
    /** @var \Drupal\encrypt\EncryptService $encryption */
    $encryption = \Drupal::service('encryption');
    return $encryption->decrypt($raw_file, $this->getEncryptionProfile($encryption_profile));
  }

  /**
   * @param string $raw_file
   * @param string $encryption_profile
   *
   * @return string
   */
  protected function encrypt($raw_file, $encryption_profile) {
    /** @var \Drupal\encrypt\EncryptService $encryption */
    $encryption = \Drupal::service('encryption');
    return $encryption->encrypt($raw_file, $this->getEncryptionProfile($encryption_profile));
  }

  /**
   * @param string $encryption_profile
   *
   * @return \Drupal\Core\Entity\EntityInterface|\Drupal\encrypt\EncryptionProfileInterface|null
   */
  protected function getEncryptionProfile($encryption_profile) {
    /** @var \Drupal\encrypt\EncryptionProfileManager $profile_manager */
    $profile_manager = \Drupal::service('encrypt.encryption_profile.manager');
    return $profile_manager->getEncryptionProfile($encryption_profile);
  }

  protected function extractEncryptionProfile($uri) {
    return $this->encryptionProfile = parse_url($uri, PHP_URL_HOST);
  }
  /**
   * {@inheritdoc}
   */
  public function stream_open($uri, $mode, $options, &$opened_path) {
    // Create Encrypted Files directory if it doesn't exist.
    $fe_directory = $this->getDirectoryPath();
    if ($fe_directory && !file_exists($fe_directory)) {
      mkdir($fe_directory, 0755);
    }

    $this->extractEncryptionProfile($uri);

    // Load resource location.
    $this->uri = $uri;
    $path = $this->getLocalPath();
    // Save the mode for later reference.
    $this->mode = $mode;
    // Load temp file as our handle.
    $this->handle = fopen('php://memory', 'w+b');
    // If file exists, decrypt and load it into memory.
    if (file_exists($path)) {
      $raw_file = file_get_contents($path);
      $decrypted_file = $this->decrypt($raw_file, $this->encryptionProfile);
      $this->setFileInfo($decrypted_file, $uri);
      // Write to memory.
      fwrite($this->handle, $decrypted_file);
      rewind($this->handle);
    }
    // Set $opened_path.
    if ((bool) $this->handle && $options & STREAM_USE_PATH) {
      $opened_path = $path;
    }

    return (bool) $this->handle;
  }

  /**
   * {@inheritdoc}
   */
  protected function getTarget($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }

    $target = parse_url($uri, PHP_URL_PATH);

    // Remove erroneous leading or trailing, forward-slashes and backslashes.
    return trim($target, '\/');
  }

  /**
   * Encrypts and writes the open file to disk, then closes the stream.
   */
  public function stream_close() {
    // If file mode we opened with is only for reading,
    // don't resave the file.
    if ((strpos($this->mode, 'r') !== FALSE) &&
      (strpos($this->mode, '+') === FALSE)) {
      fclose($this->handle);
      return;
    }
    // Encrypt file and save.
    rewind($this->handle);
    $file_contents = stream_get_contents($this->handle);
    file_put_contents($this->getLocalPath(), $this->encrypt($file_contents, $this->encryptionProfile));
    // Store important file info.
    $this->setFileInfo($file_contents, $this->uri);
    // Close handle and reset manual key.
    fclose($this->handle);
  }

  /**
   * Stores important info about the file we're operating on.
   *
   * @param string $content
   *   The content of the file
   * @param string $name
   *   The filename
   */
  protected function setFileInfo($content, $name) {
    $this->fileInfo[$name] = [
      'size' => strlen($content),
    ];
  }


}
