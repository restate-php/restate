set lazy
set default-list
set ignore-comments

# Build the PHP extension
mod extension

# The integration suite on PHP-FPM and FrankenPHP, and the soak suite (TESTING.md)
mod test 'tests'

# Load tests (TESTING.md, Load tests)
mod load 'tests/load'
