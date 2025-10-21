#!/bin/bash

set -e


npx cypress run --headless --browser chrome --config '{"specPattern":["plugins/generic/webFeed/cypress/tests/functional/*.cy.{js,jsx,ts,tsx}"]}'


