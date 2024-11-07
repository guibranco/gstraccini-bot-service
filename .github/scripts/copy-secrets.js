const axios = require('axios');
const sodium = require('libsodium-wrappers');

async function getPublicKey(owner, repo) {
  const response = await axios.get(`https://api.github.com/repos/${owner}/${repo}/dependabot/secrets/public-key`, {
    headers: {
      Authorization: `token ${process.env.GITHUB_TOKEN}`
    }
  });
  return response.data;
}

async function createOrUpdateSecret(owner, repo, secretName, encryptedValue, keyId) {
  await axios.put(`https://api.github.com/repos/${owner}/${repo}/dependabot/secrets/${secretName}`,
    {
      encrypted_value: encryptedValue,
      key_id: keyId
    },
    {
      headers: {
        Authorization: `token ${process.env.GITHUB_TOKEN}`,
        'Content-Type': 'application/json'
      }
    }
  );
}

async function encryptSecret(publicKey, secretValue) {
  await sodium.ready;
  const binkey = sodium.from_base64(publicKey.key, sodium.base64_variants.ORIGINAL);
  const binsec = sodium.from_string(secretValue);
  const encBytes = sodium.crypto_box_seal(binsec, binkey);
  return sodium.to_base64(encBytes, sodium.base64_variants.ORIGINAL);
}

async function copySecrets() {
  const [owner, repo] = process.env.GITHUB_REPOSITORY.split('/');
  const publicKey = await getPublicKey(owner, repo);

  // Example: Copy a single secret
  const secretName = 'MY_SECRET';
  const secretValue = process.env[secretName];
  const encryptedValue = await encryptSecret(publicKey, secretValue);
  await createOrUpdateSecret(owner, repo, secretName, encryptedValue, publicKey.key_id);

  // Example: Copy all secrets
  // const secrets = ['SECRET1', 'SECRET2'];
  // for (const name of secrets) {
  //   const value = process.env[name];
  //   const encryptedValue = await encryptSecret(publicKey, value);
  //   await createOrUpdateSecret(owner, repo, name, encryptedValue, publicKey.key_id);
  // }
}

copySecrets().catch(error => console.error('Error copying secrets:', error));
