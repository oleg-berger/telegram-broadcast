import unittest

result = unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.discover('tests/installer'))
if not result.wasSuccessful():
    raise SystemExit(1)
print('INSTALLER_TESTS_OK')
