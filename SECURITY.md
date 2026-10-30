# Security Policy

If you believe you have found a security vulnerability in rattle, please report it to me through coordinated disclosure.

Use my [PGP key](https://sebastian-bergmann.de/gpg.asc) for encrypted email, for example when your report includes proof-of-concept exploits against third-party systems.

**Please do not report security vulnerabilities through public GitHub issues, discussions, or pull requests.**

rattle executes the code that it fuzzes, including the target file and the bootstrap file, with the privileges of the user that runs it. Only fuzz code that you trust, or run rattle in an isolated environment.
