set lazy
set default-list
set ignore-comments

# Build the PHP extension
mod extension

# Load tests (TESTING.md, Load tests)
mod load 'tests/load'
