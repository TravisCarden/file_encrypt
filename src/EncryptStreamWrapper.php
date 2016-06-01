<?php

namespace Drupal\file_encrypt;

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\Url;
use Drupal\encrypt\EncryptionProfileInterface;

/**
 * Provides a scheme wrapper which encrypts / decrypts automatically.
 *
 * Therefore it has the encryption profile as part of the URL:
 *
 * @code
 * encrypt://example_profile/foo.txt
 * @endcode
 */
class EncryptStreamWrapper extends LocalStream {

  /**
   * Defines the schema used by the encrypt stream wrapper.
   */
  const SCHEME = 'encrypt';

  /**
   * An array of file info, each being an array.
   *
   * @var array[]
   */
  protected $fileInfo;

  /**
   * @var
   */
  protected $mode;

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
    $profile = $this->extractEncryptionProfile($this->uri);
    $path = str_replace('\\', '/', $this->getTarget());
    return Url::fromRoute('file_encrypt.file_download', ['file' => $profile->id() . '/' . $path], ['absolute' => TRUE])
      ->toString(TRUE)->getGeneratedUrl();
  }

  /**
   * Returns the base path for encrypted://.
   *
   * Note that this static method is used by \Drupal\system\Form\FileSystemForm
   * so you should alter that form or substitute a different form if you change
   * the class providing the stream_wrapper.encrypt service.
   *
   * @return string
   *   The base path for encrypt://.
   */
  public static function basePath() {
    return Settings::get('encrypted_file_path', '');
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri = NULL) {
    // This method adds the encryption profile to the URI.

    if (!$uri) {
      $uri = $this->uri;
    }
    $encryption_profile = parse_url($uri, PHP_URL_HOST);

    list($scheme) = explode('://', $uri, 2);
    $target = $this->getTarget($uri);
    $dirname = dirname($target);

    if ($dirname == '.') {
      $dirname = '';
    }

    return $scheme . '://' . $encryption_profile . '/' . $dirname;
  }

  /**
   * Decrypts a given file.
   *
   * @param string $raw_file
   *   The encrypted content of the raw file.
   * @param \Drupal\encrypt\EncryptionProfileInterface $encryption_profile
   *   The used encryption profile.
   *
   * @return string
   *   The decrypted string.
   */
  protected function decrypt($raw_file, EncryptionProfileInterface $encryption_profile) {
    /** @var \Drupal\encrypt\EncryptService $encryption */
    $encryption = \Drupal::service('encryption');
    return $encryption->decrypt($raw_file, $encryption_profile);
  }

  /**
   * Encrypts a given file.
   *
   * @param string $raw_file
   *   The descrypted content of the raw file.
   * @param \Drupal\encrypt\EncryptionProfileInterface $encryption_profile
   *   The used encryption profile.
   *
   * @return string
   *   The encrypted string.
   */
  protected function encrypt($raw_file, EncryptionProfileInterface $encryption_profile) {
    /** @var \Drupal\encrypt\EncryptService $encryption */
    $encryption = \Drupal::service('encryption');
    return $encryption->encrypt($raw_file, $encryption_profile);
  }

  /**
   * Extracta the encryption profile from an URI.
   *
   * @param string $uri
   *   The URI of the encrypt URI.
   *
   * @return \Drupal\Core\Entity\EntityInterface|\Drupal\encrypt\EncryptionProfileInterface|null The encryption profile
   * The encryption profile
   *
   * @throws \Exception
   *   Thrown when the profile doesn't exist.
   */
  protected function extractEncryptionProfile($uri) {
    /** @var \Drupal\encrypt\EncryptionProfileManager $profile_manager */
    $profile_manager = \Drupal::service('encrypt.encryption_profile.manager');
    $result = $profile_manager->getEncryptionProfile(parse_url($uri, PHP_URL_HOST));
    if (!$result) {
      throw new \Exception('Missing profile: ' . parse_url($uri, PHP_URL_HOST));
    }
    return $result;
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

    $encryption_profile = $this->extractEncryptionProfile($uri);

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
      $decrypted_file = $this->decrypt($raw_file, $encryption_profile);
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

    $encryption_profile = $this->extractEncryptionProfile($this->uri);

    // Encrypt file and save.
    rewind($this->handle);
    $file_contents = stream_get_contents($this->handle);
    file_put_contents($this->getLocalPath(), $this->encrypt($file_contents, $encryption_profile));
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
