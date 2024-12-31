SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- Table to store GitHub users and bot settings
DROP TABLE IF EXISTS github_users;
CREATE TABLE github_users (
    id BIGINT PRIMARY KEY, -- GitHub user ID (unique identifier from GitHub)
    username VARCHAR(255) NOT NULL UNIQUE, -- GitHub username
    first_name VARCHAR(255), -- User's first name
    last_name VARCHAR(255), -- User's last name
    email VARCHAR(255) UNIQUE CHECK (email REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'), -- User's email
    avatar_url TEXT, -- Link to the user's avatar image
    password VARCHAR(255) NOT NULL, -- User's password for bot authentication
    bot_settings JSON, -- JSON data to store bot-specific settings
    two_fa_methods JSON, -- JSON data to store enabled 2FA methods (e.g., "sms", "authenticator")
    recovery_codes TEXT,-- JSON string to store an array of 10 *encrypted* recovery codes
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Date user was added
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP -- Date user info was last updated
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX idx_github_users_user_id ON github_users(user_id);
CREATE INDEX idx_github_users_username ON github_users(username);
CREATE INDEX idx_github_users_email ON github_users(email);

-- Table to store GitHub installations
DROP TABLE IF EXISTS github_installations;
CREATE TABLE github_installations (
    id BIGINT PRIMARY KEY, -- GitHub installation ID
    user_id BIGINT NOT NULL, -- References github_users(id)
    access_token TEXT NOT NULL, -- Encrypted installation access token
    expires_at TIMESTAMP NOT NULL, -- Expiry date of the access token
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Date installation was added
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Date installation info was last updated
    FOREIGN KEY (user_id) REFERENCES github_users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX idx_github_installations_user_id ON github_installations(user_id);

-- Table to store GitHub repositories
DROP TABLE IF EXISTS github_repositories;
CREATE TABLE github_repositories (
    id BIGINT PRIMARY KEY, -- GitHub repository ID
    installation_id BIGINT NOT NULL, -- References github_installations(id)
    name VARCHAR(255) NOT NULL, -- Repository name
    full_name VARCHAR(255) NOT NULL UNIQUE, -- Full name (e.g., user/repo)
    private BOOLEAN NOT NULL DEFAULT FALSE, -- Whether the repo is private
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Date repository was added
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Date repository info was last updated
    FOREIGN KEY (installation_id) REFERENCES github_installations (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX idx_github_repositories_user_id ON github_repositories(user_id);
CREATE INDEX idx_github_repositories_installation_id ON github_repositories(installation_id);
CREATE INDEX idx_github_repositories_name ON github_repositories(name);

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
CREATE INDEX idx_github_integrations_user_id ON github_integrations(user_id);

-- Table to store notifications
DROP TABLE IF EXISTS notifications;
CREATE ENUM notification_type AS ENUM ('info', 'warning', 'error');
CREATE TABLE notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, -- Unique identifier for the notification
    user_id BIGINT NOT NULL, -- References github_users(id)
    
    message TEXT NOT NULL, -- Notification message
    type notification_type NOT NULL, -- Type of notification (e.g., "info", "warning", "error")
    is_read BOOLEAN NOT NULL DEFAULT FALSE, -- Whether the notification has been read
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP -- Date notification was created
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX idx_notifications_user_id ON notifications(user_id);
CREATE INDEX idx_notifications_is_read ON notifications(is_read);
CREATE INDEX idx_notifications_type ON notifications(type);
CREATE INDEX idx_notifications_user_unread ON notifications(user_id, is_read);

-- Table to store recent activities
DROP TABLE IF EXISTS recent_activities;
CREATE TABLE recent_activities (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, -- Unique identifier for the activity
    user_id BIGINT NOT NULL, -- References github_users(id)
    action ENUM('merged_pr', 'created_issue', 'commented', 'reviewed') NOT NULL, -- Description of the activity (e.g., "merged a pull request")
    metadata JSON, -- Additional data about the activity (e.g., repository, pull request ID)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Date the activity occurred
    FOREIGN KEY (user_id) REFERENCES github_users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX idx_recent_activities_user_created ON recent_activities(user_id, created_at DESC);

-- Table to store pending actions
DROP TABLE IF EXISTS pending_actions;
CREATE TABLE pending_actions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, -- Unique identifier for the action
    user_id BIGINT NOT NULL, -- References github_users(id)
    action ENUM('review_pr', 'fix_issue', 'update_deps') NOT NULL, -- Description of the action (e.g., "review a pull request")
    metadata JSON, -- Additional data about the action (e.g., repository, pull request ID)
    due_date TIMESTAMP, -- Optional deadline for the action
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Date the action was created
    FOREIGN KEY (user_id) REFERENCES github_users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX idx_pending_actions_user_due ON pending_actions(user_id, due_date);
CREATE INDEX idx_pending_actions_due ON pending_actions(due_date) WHERE due_date IS NOT NULL;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
