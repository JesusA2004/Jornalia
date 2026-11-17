// Metro: permite importar el contrato API compartido (`../packages/contracts`)
// mediante el alias `@contracts/*` definido en tsconfig.json.
// https://docs.expo.dev/guides/monorepos/
const path = require('path');
const { getDefaultConfig } = require('expo/metro-config');

const projectRoot = __dirname;
const contractsRoot = path.resolve(projectRoot, '../packages/contracts');

const config = getDefaultConfig(projectRoot);

config.watchFolders = [...(config.watchFolders ?? []), contractsRoot];
config.resolver.nodeModulesPaths = [path.resolve(projectRoot, 'node_modules')];

// No rastrear las carpetas nativas generadas (builds de Gradle/CMake muy grandes).
const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/(\\\\|\/)/g, '[\\\\/]');
config.resolver.blockList = [
  new RegExp(`^${escapeRe(path.join(projectRoot, 'android'))}[\\\\/].*`),
  new RegExp(`^${escapeRe(path.join(projectRoot, 'ios'))}[\\\\/].*`),
];

module.exports = config;
