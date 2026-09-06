package security

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"io"
)

// Encrypt encrypts plaintext with AES-256-GCM using a 32-byte key (hex or raw).
func Encrypt(keyMaterial, plaintext []byte) ([]byte, error) {
	key, err := normalizeKey(keyMaterial)
	if err != nil {
		return nil, err
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}
	nonce := make([]byte, gcm.NonceSize())
	if _, err := io.ReadFull(rand.Reader, nonce); err != nil {
		return nil, err
	}
	return gcm.Seal(nonce, nonce, plaintext, nil), nil
}

func Decrypt(keyMaterial, ciphertext []byte) ([]byte, error) {
	key, err := normalizeKey(keyMaterial)
	if err != nil {
		return nil, err
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}
	if len(ciphertext) < gcm.NonceSize() {
		return nil, errors.New("ciphertext too short")
	}
	nonce, ct := ciphertext[:gcm.NonceSize()], ciphertext[gcm.NonceSize():]
	return gcm.Open(nil, nonce, ct, nil)
}

func normalizeKey(m []byte) ([]byte, error) {
	if len(m) == 32 {
		return m, nil
	}
	if len(m) == 64 {
		out := make([]byte, 32)
		if _, err := hex.Decode(out, m); err != nil {
			return nil, err
		}
		return out, nil
	}
	return nil, errors.New("ENCRYPTION_KEY must be 32 bytes or 64 hex chars")
}
