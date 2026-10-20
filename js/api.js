export async function api(path, options = {}) {
  const config = window.wpDuckdb;
  const response = await fetch(`${config.restUrl}${path}`, {
    method: options.method ?? 'GET',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-WP-Nonce': config.nonce,
    },
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });

  let data = {};
  try {
    data = await response.json();
  } catch {
    data = {};
  }

  if (!response.ok) {
    const error = new Error(data.message || `Request failed (${response.status})`);
    error.code = data.code;
    throw error;
  }

  return data;
}
