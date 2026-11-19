import prettier from 'prettier/standalone';
import html from 'prettier/plugins/html';
import postcss from 'prettier/plugins/postcss';
import babel from 'prettier/plugins/babel';
import estree from 'prettier/plugins/estree';
import { config } from './api';

const languages = {
    html: { parser: 'html', plugins: [html], htmlWhitespaceSensitivity: 'strict' },
    css: { parser: 'css', plugins: [postcss] },
    js: { parser: 'babel', plugins: [babel, estree] },
};

export async function formatSource(key, source) {
    return prettier.format(source, { ...languages[key], tabWidth: config.tabSize });
}
