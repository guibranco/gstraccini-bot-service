-- Table to store GitHub users and bot settings
DROP TABLE IF EXISTS github_users;
CREATE TABLE github_users (
    id BIGINT PRIMARY KEY, -- GitHub user ID (unique identifier from GitHub)
    username VARCHAR(255) NOT NULL, -- GitHub username
    email VARCHAR(255), -- User's email
    avatar_url TEXT, -- Link to the user's avatar image
    settings JSON, -- JSON data to store bot-specific settings
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Date user was added
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP -- Date user info was last updated
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Table to store GitHub installations
DROP TABLE IF EXISTS github_installations;
CREATE TABLE github_installations (
    id BIGINT PRIMARY KEY, -- GitHub installation ID
    user_id BIGINT NOT NULL, -- References github_users(id)
    access_token TEXT NOT NULL, -- Installation access token
    expires_at TIMESTAMP NOT NULL, -- Expiry date of the access token
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Date installation was added
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Date installation info was last updated
    FOREIGN KEY (user_id) REFERENCES github_users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Table to store GitHub repositories
DROP TABLE IF EXISTS github_repositories;
CREATE TABLE github_repositories (
    id BIGINT PRIMARY KEY, -- GitHub repository ID
    installation_id BIGINT NOT NULL, -- References github_installations(id)
    name VARCHAR(255) NOT NULL, -- Repository name
    full_name VARCHAR(255) NOT NULL, -- Full name (e.g., user/repo)
    private BOOLEAN NOT NULL DEFAULT FALSE, -- Whether the repo is private
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Date repository was added
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Date repository info was last updated
    FOREIGN KEY (installation_id) REFERENCES github_installations (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Table to store security data
DROP TABLE IF EXISTS github_security_data;
CREATE TABLE github_security_data (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, -- Unique identifier for the security data
    user_id BIGINT NOT NULL, -- References github_users(id)
    two_fa_methods JSON, -- JSON data to store enabled 2FA methods (e.g., "sms", "authenticator")
    recovery_codes TEXT, -- JSON string to store an array of 10 recovery codes
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Date security data was added
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Date security data was last updated
    FOREIGN KEY (user_id) REFERENCES github_users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Table to store integrations data
DROP TABLE IF EXISTS github_integrations;
CREATE TABLE github_integrations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, -- Unique identifier for the integration
    user_id BIGINT NOT NULL, -- References github_users(id)
    api_key TEXT NOT NULL, -- API key for the integration
    status VARCHAR(50) NOT NULL DEFAULT 'active', -- Status of the integration (e.g., "active", "inactive", "error")
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Date integration was added
    date_error TIMESTAMP NULL DEFAULT NULL, -- Date of the last error, if applicable
    last_error TEXT, -- Description of the last error, if applicable
    FOREIGN KEY (user_id) REFERENCES github_users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Table to store notifications
DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, -- Unique identifier for the notification
    user_id BIGINT NOT NULL, -- References github_users(id)
    message TEXT NOT NULL, -- Notification message
    type VARCHAR(50) NOT NULL, -- Type of notification (e.g., "info", "warning", "error")
    is_read BOOLEAN NOT NULL DEFAULT FALSE, -- Whether the notification has been read
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP -- Date notification was created
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
