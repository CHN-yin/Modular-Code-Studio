import apiFetch from '@wordpress/api-fetch';

export const config = window.MCS?.editor || {};

export function request(id, action, data, signal) {
    return apiFetch({
        path: `/mcs/v1/posts/${id}/${action}`,
        method: data === undefined ? 'GET' : 'POST',
        headers: { 'X-WP-Nonce': config.nonce },
        data,
        signal,
    });
}
