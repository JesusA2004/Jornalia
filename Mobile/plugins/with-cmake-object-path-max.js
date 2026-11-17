/**
 * Config plugin: limita la longitud de las rutas de objetos C++ en el build
 * nativo de Android (CMAKE_OBJECT_PATH_MAX=250).
 *
 * Motivo: en Windows, ninja 1.10 (CMake 3.22 del SDK) no admite rutas de más de
 * 260 caracteres y algunos módulos (p. ej. react-native-gesture-handler) generan
 * rutas más largas al compilar desde C:\wamp64\www\Jornalia\Mobile. Con este
 * valor, CMake abrevia los directorios de objetos con un hash MD5.
 * No afecta a builds en macOS/Linux ni en EAS.
 */
const { withAppBuildGradle } = require('expo/config-plugins');

const MARKER = '// mrlana:cmake-object-path-max';

module.exports = function withCmakeObjectPathMax(config, { max = 250 } = {}) {
  return withAppBuildGradle(config, (cfg) => {
    if (!cfg.modResults.contents.includes(MARKER)) {
      cfg.modResults.contents += `
${MARKER}
android {
    defaultConfig {
        externalNativeBuild {
            cmake {
                arguments "-DCMAKE_OBJECT_PATH_MAX=${max}"
            }
        }
    }
}
`;
    }
    return cfg;
  });
};
