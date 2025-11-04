#!/bin/bash

# iWheb Auth API Test Script
# Interactive script to test all API endpoints

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
DEFAULT_BASE_URL="http://localhost:8080"
DEFAULT_API_KEY="vR99zKCSLudvIYomlBb9zJyx5hiYqpeq"

# Helper functions
print_header() {
    echo -e "\n${BLUE}================================${NC}"
    echo -e "${BLUE} $1${NC}"
    echo -e "${BLUE}================================${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_info() {
    echo -e "${YELLOW}ℹ️  $1${NC}"
}

ask_input() {
    local prompt="$1"
    local default="$2"
    local result
    
    if [ -n "$default" ]; then
        read -p "$prompt [$default]: " result
        echo "${result:-$default}"
    else
        read -p "$prompt: " result
        echo "$result"
    fi
}

encode_email() {
    local email="$1"
    echo -n "$email" | base64 -w 0
}

make_request() {
    local method="$1"
    local endpoint="$2"
    local data="$3"
    local description="$4"
    
    echo -e "\n${YELLOW}Request:${NC} $method $BASE_URL$endpoint"
    if [ -n "$data" ]; then
        echo -e "${YELLOW}Data:${NC} $data"
    fi
    
    local cmd="curl -s -w '\nHTTP Status: %{http_code}\n' -X $method '$BASE_URL$endpoint' -H 'X-API-Key: $API_KEY'"
    
    if [ "$method" != "GET" ]; then
        cmd="$cmd -H 'Content-Type: application/json'"
        if [ -n "$data" ]; then
            cmd="$cmd -d '$data'"
        else
            cmd="$cmd -d '{}'"
        fi
    fi
    
    echo -e "\n${BLUE}Response:${NC}"
    eval "$cmd" | jq -R 'fromjson? // .'
    echo
}

extract_session_id() {
    local response="$1"
    echo "$response" | jq -r '.session_id // empty' 2>/dev/null || echo ""
}

# Main script
print_header "iWheb Auth API Test Script"

echo "This script will help you test all API endpoints interactively."
echo

# Get configuration
BASE_URL=$(ask_input "Base URL" "$DEFAULT_BASE_URL")
API_KEY=$(ask_input "API Key" "$DEFAULT_API_KEY")

echo -e "\n${GREEN}Configuration:${NC}"
echo "Base URL: $BASE_URL"
echo "API Key: ${API_KEY:0:8}..."

# Main menu
while true; do
    print_header "Available API Endpoints"
    echo "1. POST /login - Start authentication"
    echo "2. POST /validate/{session_id} - Validate auth code"
    echo "3. GET /session/check/{session_id} - Check session status"
    echo "4. POST /session/touch/{session_id} - Extend session"
    echo "5. POST /session/delegate/{session_id} - Create delegated session"
    echo "6. POST /session/logout/{session_id} - Logout session"
    echo "7. GET /user/{session_id}/info - Get user info"
    echo "8. GET /user/{session_id}/token - Get user token"
    echo "9. GET /user/{session_id}/id - Get user ID"
    echo "10. Full Authentication Flow"
    echo "0. Exit"
    echo
    
    choice=$(ask_input "Choose an option (0-10)")
    
    case $choice in
        1)
            print_header "POST /login"
            print_info "This will start the authentication process and send an email with a verification code."
            
            email=$(ask_input "Email address")
            encoded_email=$(encode_email "$email")
            
            data="{\"email\": \"$encoded_email\"}"
            response=$(curl -s -X POST "$BASE_URL/login" \
                -H "Content-Type: application/json" \
                -H "X-API-Key: $API_KEY" \
                -d "$data")
            
            make_request "POST" "/login" "$data" "Start authentication"
            
            # Save session ID for later use
            SESSION_ID=$(echo "$response" | jq -r '.session_id // empty' 2>/dev/null)
            if [ -n "$SESSION_ID" ]; then
                print_success "Session ID saved: $SESSION_ID"
                export LAST_SESSION_ID="$SESSION_ID"
            fi
            ;;
            
        2)
            print_header "POST /validate/{session_id}"
            print_info "This validates the 6-digit code sent to your email."
            
            session_id=$(ask_input "Session ID" "${LAST_SESSION_ID}")
            code=$(ask_input "6-digit verification code")
            
            data="{\"code\": \"$code\"}"
            make_request "POST" "/validate/$session_id" "$data" "Validate auth code"
            ;;
            
        3)
            print_header "GET /session/check/{session_id}"
            print_info "This checks if a session is active and validated."
            
            session_id=$(ask_input "Session ID" "${LAST_SESSION_ID}")
            make_request "GET" "/session/check/$session_id" "" "Check session status"
            ;;
            
        4)
            print_header "POST /session/touch/{session_id}"
            print_info "This extends the session lifetime by updating last activity."
            
            session_id=$(ask_input "Session ID" "${LAST_SESSION_ID}")
            make_request "POST" "/session/touch/$session_id" "{}" "Extend session"
            ;;
            
        5)
            print_header "POST /session/delegate/{session_id}"
            print_info "This creates a child session for a specific API key."
            
            session_id=$(ask_input "Session ID" "${LAST_SESSION_ID}")
            target_key=$(ask_input "Target API Key")
            
            data="{\"target_api_key\": \"$target_key\"}"
            response=$(curl -s -X POST "$BASE_URL/session/delegate/$session_id" \
                -H "Content-Type: application/json" \
                -H "X-API-Key: $API_KEY" \
                -d "$data")
            
            make_request "POST" "/session/delegate/$session_id" "$data" "Create delegated session"
            
            # Save new session ID (for current API key) and delegated session ID
            NEW_SESSION_ID=$(echo "$response" | jq -r '.session_id // empty' 2>/dev/null)
            DELEGATED_SESSION_ID=$(echo "$response" | jq -r '.delegated_session.session_id // empty' 2>/dev/null)
            
            if [ -n "$NEW_SESSION_ID" ]; then
                print_success "New Session ID: $NEW_SESSION_ID"
                export session_id="$NEW_SESSION_ID"  # Update current session for next operations
            fi
            
            if [ -n "$DELEGATED_SESSION_ID" ]; then
                print_success "Delegated Session ID: $DELEGATED_SESSION_ID"
                export LAST_DELEGATED_SESSION_ID="$DELEGATED_SESSION_ID"
            fi
            ;;
            
        6)
            print_header "POST /session/logout/{session_id}"
            print_info "This terminates the session and all child sessions."
            
            session_id=$(ask_input "Session ID" "${LAST_SESSION_ID}")
            make_request "POST" "/session/logout/$session_id" "{}" "Logout session"
            ;;
            
        7)
            print_header "GET /user/{session_id}/info"
            print_info "This retrieves user information from Webling."
            
            session_id=$(ask_input "Session ID" "${LAST_SESSION_ID}")
            make_request "GET" "/user/$session_id/info" "" "Get user info"
            ;;
            
        8)
            print_header "GET /user/{session_id}/token"
            print_info "This retrieves an encrypted user token."
            
            session_id=$(ask_input "Session ID" "${LAST_SESSION_ID}")
            make_request "GET" "/user/$session_id/token" "" "Get user token"
            ;;
            
        9)
            print_header "GET /user/{session_id}/id"
            print_info "This retrieves the decrypted Webling user ID."
            
            session_id=$(ask_input "Session ID" "${LAST_SESSION_ID}")
            make_request "GET" "/user/$session_id/id" "" "Get user ID"
            ;;
            
        10)
            print_header "Full Authentication Flow"
            print_info "This will run through the complete authentication process."
            
            # Step 1: Login
            echo -e "\n${YELLOW}Step 1: Login${NC}"
            email=$(ask_input "Email address")
            encoded_email=$(encode_email "$email")
            
            data="{\"email\": \"$encoded_email\"}"
            response=$(curl -s -X POST "$BASE_URL/login" \
                -H "Content-Type: application/json" \
                -H "X-API-Key: $API_KEY" \
                -d "$data")
            
            make_request "POST" "/login" "$data" "Start authentication"
            
            SESSION_ID=$(echo "$response" | jq -r '.session_id // empty' 2>/dev/null)
            if [ -z "$SESSION_ID" ]; then
                print_error "Failed to get session ID from login response"
                continue
            fi
            
            print_success "Session ID: $SESSION_ID"
            export LAST_SESSION_ID="$SESSION_ID"
            
            # Step 2: Wait for user to check email
            echo -e "\n${YELLOW}Step 2: Email Verification${NC}"
            print_info "Check your email for the verification code."
            
            code=$(ask_input "Enter the 6-digit verification code")
            
            data="{\"code\": \"$code\"}"
            make_request "POST" "/validate/$SESSION_ID" "$data" "Validate auth code"
            
            # Step 3: Check session
            echo -e "\n${YELLOW}Step 3: Check Session${NC}"
            make_request "GET" "/session/check/$SESSION_ID" "" "Check session status"
            
            # Step 4: Get user info
            echo -e "\n${YELLOW}Step 4: Get User Info${NC}"
            make_request "GET" "/user/$SESSION_ID/info" "" "Get user info"
            
            # Step 5: Touch session
            echo -e "\n${YELLOW}Step 5: Touch Session${NC}"
            make_request "POST" "/session/touch/$SESSION_ID" "{}" "Extend session"
            
            # Step 6: Get user token
            echo -e "\n${YELLOW}Step 6: Get User Token${NC}"
            make_request "GET" "/user/$SESSION_ID/token" "" "Get user token"
            
            # Step 7: Optional logout
            echo -e "\n${YELLOW}Step 7: Logout (Optional)${NC}"
            logout=$(ask_input "Do you want to logout? (y/N)" "N")
            if [[ "$logout" =~ ^[Yy]$ ]]; then
                make_request "POST" "/session/logout/$SESSION_ID" "{}" "Logout session"
            fi
            
            print_success "Full authentication flow completed!"
            ;;
            
        0)
            print_info "Goodbye!"
            exit 0
            ;;
            
        *)
            print_error "Invalid choice. Please select 0-10."
            ;;
    esac
    
    echo
    read -p "Press Enter to continue..."
done